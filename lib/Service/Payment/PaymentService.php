<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentErrorCode;
use OCA\Libresign\Enum\PaymentFlow;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Service\DateTimeService;
use OCA\Libresign\Service\Payment\Dispatchers\VerificationDispatcherFactory;
use OCA\Libresign\Service\Payment\DarajaService;
use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyResultDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\DTO\SuggestedMnoDTO;
use OCA\Libresign\Service\Payment\Exceptions\PaymentValidationException;
use OCA\Libresign\Service\Payment\Exceptions\PaymentInvariantException;
use OCA\Libresign\Service\Payment\Exceptions\PaymentNotFoundException;
use OCA\Libresign\Service\Payment\Exceptions\PaymentOwnershipException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Core payment orchestration service.
 *
 * Responsibilities:
 * - Start payments (DPO / Daraja)
 * - Handle idempotency
 * - Persist payment state
 * - Verify payments
 * - Process Daraja callbacks
 */
class PaymentService
{

	public function __construct(
		private PaymentMapper $paymentMapper,
		private LoggerInterface $logger,
		private AmountResolver $amountResolver,
		private MobileMoneyService $mobileMoneyService,
		private CardService $cardService,
		private VerificationService $verificationService,
		private DateTimeService $dateTimeService,
		private PaymentContextService $context,
		private PaymentValidationService $validation,
		private PaymentLifecycleService $lifecycle,
		private PaymentReconciliationService $reconciliation,
		private PaymentMetadataHelperService $metadataHelper,
		private VerificationDispatcherFactory $verificationDispatcher,
	) {}

	/**
	 * Start a new payment attempt.
	 *
	 * Flow:
	 * 1. Validate request (capability-driven)
	 * 2. Resolve phone → detect MNO → merge confidence (mobile only)
	 * 3. Route → capability (mobile_money | card)
	 * 4. Prevent duplicates / enforce idempotency
	 * 5. Persist payment FIRST (critical for async callbacks)
	 * 6. Execute via capability service
	 * 7. Persist provider reference
	 * 8. Return FE contract
	 *
	 * Notes:
	 * - Amounts stored in minor units (DB), converted to major for providers
	 * - Confidence is merged conservatively (never upgraded)
	 * - Providers never handle phone parsing
	 *
	 * @throws \Throwable
	 */
	public function startPayment(StartPaymentDTO $dto): StartPaymentResultDTO|ExistingPaymentResultDTO
	{
		// 1 — ROUTE (validates method/phone/redirect, resolves phone, routes, hint)
		$ctx = $this->context->resolveRoute($dto);

		$this->logger->info('Starting payment', [
			'sign_uuid' => $dto->signUuid,
			'sign_request_id' => $dto->signRequestId,
			'capability' => $ctx->capability->value,
			'method' => $dto->paymentMethod->value,
			'route_provider' => $ctx->route->preferredProvider->value ?? null,
			'route_confidence' => $ctx->route->confidence->value ?? null,
			'route_mno_key' => $ctx->route->mnoKey ?? null,
		]);

		// 2 — GUARD (dedup / idempotency, three-way)
		$outcome = $this->validation->guard($dto, $ctx);

		if ($outcome->shouldReturnExisting()) {
			return $this->buildExistingPaymentResponse($outcome->existingPayment);
		}
		$attemptId = $outcome->resolvedAttemptId;

		// 3 — PRICE (product → FX → provider-normalised amount)
		$pricing = $this->context->price($dto, $ctx);

		$paymentPurpose = PaymentPurpose::tryFrom($dto->purpose->value) ?? PaymentPurpose::SIGN_REQUEST;

		// 4 — CREATE (persist BEFORE provider call, critical for async callbacks)
		$payment = new Payment();
		$payment->setPaymentAttemptId($attemptId);
		$payment->setPaymentPurpose($paymentPurpose);
		$payment->setTransactionId($dto->signRequestId);
		$payment->setTransactionReference($dto->signUuid);
		$payment->setAmount($pricing->amountMinor);
		$payment->setCurrency($pricing->currency);
		$payment->setUserId($dto->userId);
		$payment->setPhoneE164Digits($ctx->e164);
		$payment->setPhoneRegion($ctx->route->region ?? null);
		$payment->setPhoneCountry($ctx->route->country ?? null);
		$payment->setProductCode($dto->productCode);
		$payment->setProductUses($pricing->uses);
		$payment->setQuantity($pricing->quantity);
		$payment->setUnitAmount($pricing->unitAmount);
		$payment->setPaymentStatus(PaymentStatus::PENDING);
		$payment->setProvider($ctx->route->preferredProvider->value);
		$payment->setCreatedAt($this->dateTimeService->now());
		$payment->setDisplayAmount($pricing->fx->displayAmount);
		$payment->setDisplayCurrency($pricing->fx->displayCurrency);
		$payment->setFxRate($pricing->fx->fxRate);
		$payment->setFxRateSource($pricing->fx->fxRateSource);
		$payment->setFxRateLockedAt($pricing->fx->fxRateLockedAt->format(DATE_ATOM));

		$payment->validate();
		$this->paymentMapper->insert($payment);

		// 5 — EXECUTE
		try {
			$res = match ($ctx->route->capability) {
				PaymentCapability::MOBILE_MONEY => $this->mobileMoneyService->initiate(
					$ctx->route,
					new MobileMoneyPayloadDTO(
						phone: $ctx->e164,
						amount: $pricing->normalisedAmount,
						currency: $pricing->fx->displayCurrency,
						signUuid: $dto->signUuid,
						signRequestId: $dto->signRequestId,
						userId: $dto->userId,
						email: $dto->userEmail,
						callbackUrl: $dto->callbackUrl,
						redirectUrl: $dto->redirectUrl,
					)
				),
				PaymentCapability::CARD => $this->cardService->initiateCard(
					new CardPaymentPayloadDTO(
						amount: $pricing->normalisedAmount,
						currency: $pricing->fx->displayCurrency,
						userId: $dto->userId,
						email: $dto->userEmail,
						redirectUrl: $dto->redirectUrl,
						callbackUrl: $dto->callbackUrl,
					)
				),
				default => throw new PaymentInvariantException('Unsupported capability'),
			};
		} catch (\Throwable $e) {

			$this->lifecycle->failPaymentInitiation($payment, $e->getMessage(), $ctx->route->preferredProvider, $e);
			return $this->buildExistingPaymentResponse($payment);

		}

		// 6 — VALIDATE PROVIDER RESPONSE
		try {

			$this->validateProviderResult($res);

		} catch (RuntimeException $e) {

			$message = 'Invalid provider response: ' . ($res->message ?? 'empty result');
			$this->lifecycle->failPaymentInitiation($payment, $message, $res->provider, $e);
			return $this->buildExistingPaymentResponse($payment);

		}

		// 7 — PERSIST REFERENCE + METADATA
		$payment->setPaymentProvider($res->provider);
		$payment->setProviderReference($res->providerReference);

		$metaPayload = is_array($res->meta ?? [])
						? ($res->meta ?? [])
						: [];

		$selected = new SelectedMnoDTO(
			mno: null,
			country: null
		);

		$selection = ($metaPayload['selection'] ?? null) instanceof SelectionDTO
			? $metaPayload['selection']
			: new SelectionDTO(
				required: false,
				options: []
			);

		$suggested = ($metaPayload['suggested'] ?? null) instanceof SuggestedMnoDTO
			? $metaPayload['suggested']
			: new SuggestedMnoDTO(
				mno: null,
				country: null
			);

		$metadata = new PaymentMetadataDTO(
			updatedAt: $this->dateTimeService->nowImmutable(),
			preferredProvider: $ctx->route->preferredProvider->value,
			executedProvider: $res->provider->value,
			flow: $res->flow->value,
			method: $dto->paymentMethod->value,
			redirectUrl: $res instanceof CardPaymentResultDTO ? $res->redirectUrl : null,
			selected: $selected,
			suggested: $suggested,
			selection: $selection,
			confidence: $metaPayload['confidence'] ?? $ctx->route->confidence->value,
			alreadyCharged: $res->providerExecutionState->hasExecutionStarted(),
			instructions: $metaPayload['instructions'] ?? null,
			context: $ctx->ctxMetadata,
			providerExecutionState: $res->providerExecutionState,
			providerPayload: ProviderPayloadDTO::fromArray($metaPayload['providerPayload'] ?? []),
			providerError: null,
		);

		$payment->setProviderMetadataObject($metadata);
		$this->paymentMapper->update($payment);

		try {
			$this->validateInitiatedPayment($payment);
		} catch (RuntimeException $e) {
			$this->lifecycle->failPaymentInitiation($payment, $e->getMessage(), $payment->getProviderEnum(), $e);
			return $this->buildExistingPaymentResponse($payment);
		}

		// 8 — RESPONSE (minor → major for FE)
		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();
		$displayAmount = null;
		$displayAmountFormatted = null;

		if ($displayAmountMinor !== null && $displayCurrency !== null) {
			$displayAmount = $this->amountResolver->toMajorUnits($displayAmountMinor, $displayCurrency);
			$displayAmountFormatted = $this->amountResolver->format($displayAmountMinor, $displayCurrency);
		}

		$metadata = $payment->getProviderMetadataObject();

		return new StartPaymentResultDTO(
			updatedAt: $payment->getUpdatedAtImmutable(),
			paymentId: $payment->getId(),
			signRequestId: $payment->getTransactionId(),
			signUuid: $payment->getTransactionReference(),
			reference: $payment->getProviderReference(),
			provider: $payment->getProviderEnum(),
			flow: PaymentFlow::from($metadata->flow),
			method: PaymentMethod::from($metadata->method),
			redirectUrl: $metadata->redirectUrl,
			mno: $metadata->suggested->mno,
			country: $metadata->suggested->country,
			alreadyCharged: $metadata->alreadyCharged,
			providerExecutionState: $metadata->providerExecutionState,
			selected: $selected,
			confidence: $metadata->confidence,
			requiresProviderSelection: $metadata->selection->required,
			options: $metadata->selection->options,
			phoneNumber: $payment->getPhoneE164Digits(),
			phoneNumberRegion: $payment->getPhoneRegion(),
			phoneNumberCountry: $payment->getPhoneCountry(),
			displayAmount: $displayAmount,
			displayAmountFormatted: $displayAmountFormatted,
			displayCurrency: $payment->getDisplayCurrency(),
			paymentPurpose: $payment->getPaymentPurpose(),
			quantity: $payment->getQuantity(),
			unitAmount: $payment->getUnitAmount(),
		);
	}

	/**
	 * Explicit user-initiated retry of a timed-out payment session.
	 *
	 * Reconciles the existing reference with the provider FIRST (a delayed
	 * success must never be discarded), then — only if not paid — expires it
	 * and starts a fresh attempt from the re-supplied intent.
	 *
	 * Age-independent by design: an explicit retry always reconciles against
	 * the provider rather than guessing from a timestamp, so it does not
	 * depend on PAYMENT_STALE_FOR_RETRY_SECONDS (that gate now only guards
	 * accidental double-submit inside startPayment).
	 */
	public function retryPayment(
		string $reference,
		StartPaymentDTO $dto,
	): StartPaymentResultDTO | ExistingPaymentResultDTO {

		$payment = $this->assertPaymentOwnership($reference, $dto->userId);

		$status = $this->reconciliation->reconcileThenExpire($payment, 'superseded_by_retry');

		// Delayed success landed while the user was on the recovery screen.
		// /payment/retry legitimately returns PAID; FE shows success.
		if ($status === PaymentStatus::PAID) {
			$paid = $this->paymentMapper->findByProviderReferenceOrFail($reference);
			return $this->buildExistingPaymentResponse($paid);
		}

		// Old row is now terminal → rotate the attempt id so startPayment's
		// idempotency lookup (findByAttemptId) can't match the row we just
		// failed. Without this, the re-supplied payload's original attempt id
		// might return the FAILED payment instead of starting fresh.
		return $this->startPayment(
			$dto->withPaymentAttemptId(Uuid::uuid4()->toString())
		);
	}


	/**
	 * Get payment status (READ-ONLY)
	 *
	 * RULES:
	 * - Does NOT call provider APIs
	 * - Returns DB state only (source of truth)
	 * - Background job is responsible for syncing provider status
	 *
	 * FLOW:
	 * - If resolved → return immediately
	 * - If expired → mark as failed
	 * - Otherwise → return current state (likely PENDING)
	 */
	public function getPaymentStatus(string $reference): PaymentStatus
	{
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		// Fast path → already resolved
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		// Handle expiration locally (no provider call needed)
		if ($this->validation->hasPaymentExpired($payment)) {

			$this->lifecycle->markFailed($payment, 'expired');

			return PaymentStatus::FAILED;
		}

		// IMPORTANT:
		// Do NOT call verificationService here.
		// Status might be updated asynchronously by the background job.
		return $payment->getPaymentStatus();
	}


	/**
	 * Restore an existing payment session for frontend hydration.
	 *
	 * IMPORTANT:
	 * - This is NOT provider verification
	 * - This does NOT initiate/retry payment
	 * - This only restores the current persisted payment state
	 *
	 * Frontend uses this to:
	 * - restore payment UX
	 * - restore provider/MNO selection
	 * - restore FX display context
	 * - decide whether polling can resume
	 *
	 * Backend remains the source of truth.
	 *
	 */
	public function resumePayment(
		PaymentPurpose $purpose,
		?int $signRequestId = null,
		?string $signUuid = null,
		?string $productCode = null,
		?string $userId = null,
	): ExistingPaymentResultDTO | null {

		/**
		 * Resolve pending payment based on purpose
		 */
		$payment = match ($purpose) {

			PaymentPurpose::SIGN_REQUEST =>
			$signRequestId !== null
				? $this->paymentMapper
				->findLatestPendingByTransactionId($signRequestId)
				: null,

			PaymentPurpose::CREDIT_PURCHASE =>
			$userId !== null && $productCode !== null
				? $this->paymentMapper
				->findLatestPendingCreditPurchaseByUserId(
					$userId,
					$productCode
				)
				: null,
		};

		if (!$payment) {
			return null;
		}

		/**
		 * ENTITY REHYDRATION (Nextcloud ORM quirk)
		 *
		 * The finder queries above may return a partially-hydrated entity.
		 * Re-fetch by id to guarantee a fully-mapped row before we read
		 * ownership fields and build the FE response.
		 */
		$payment = $this->paymentMapper->findById($payment->getId());

		if (!$payment) {
			return null;
		}

		/**
		 * SIGN REQUEST OWNERSHIP VALIDATION
		 *
		 * signUuid is NOT unique — one signUuid can span multiple sign
		 * requests. So resuming a SIGN_REQUEST payment requires BOTH keys
		 * to match: the signUuid (transactionReference) AND the specific
		 * signRequestId (transactionId). Matching signUuid alone would let
		 * one sign request resume another's payment under a shared uuid.
		 */
		if (
			$purpose === PaymentPurpose::SIGN_REQUEST &&
			(
				$payment->getTransactionReference() !== $signUuid ||
				$payment->getTransactionId() !== $signRequestId
			)
		) {
			return null;
		}

		/**
		 * Authenticated ownership validation
		 *
		 * All users have accounts, so a
		 * pending payment always has an owning userId. When a userId is
		 * supplied by the caller, it must match the payment's owner.
		 *
		 * The $payment->getUserId() !== null guard is retained defensively
		 */
		if (
			$userId !== null &&
			$payment->getUserId() !== null &&
			$payment->getUserId() !== $userId
		) {
			return null;
		}

		/**
		 * Expired sessions are invalidated eagerly
		 */
		if ($this->validation->hasPaymentExpired($payment)) {
			$this->lifecycle->expirePayment($payment);
			return null;
		}

		/**
		 * Defensive status validation
		 */
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return null;
		}

		/**
		 * Hydrate canonical FE payment state
		 */
		return $this->buildExistingPaymentResponse(
			$payment
		);
	}

	/**
	 * Invalidate an active payment reference at the user's explicit request.
	 *
	 * Called from the recovery flow when a user chooses to restart a payment
	 * that has timed out on the frontend (missed STK push, dismissed prompt,
	 * wrong PIN, or switching numbers). This is a deliberate user action —
	 * distinct from automatic expiry (hasPaymentExpired) or stale-retry
	 * reconciliation (isPendingStaleForRetry).
	 *
	 * BEHAVIOUR:
	 * - Idempotent: calling on an already-terminal payment (PAID / FAILED /
	 *   CANCELLED) is a no-op that returns the current status. This protects
	 *   against double-invalidation from rapid clicks or retried requests.
	 * - PAID guard: if the payment completed while the user was on the recovery
	 *   screen (delayed callback landed), we do NOT invalidate — the money moved.
	 *   The caller should surface the success instead.
	 * - Provider cancellation is best-effort: DPO tokens can be cancelled via the
	 *   verification service; Daraja has no cancellation API, so we only mark the
	 *   row FAILED and let the reference expire naturally on Safaricom's side.
	 * - The row is marked FAILED with providerError.type = 'invalidated_by_user'
	 *   so it is distinguishable from timeouts and genuine failures in reporting.
	 *
	 * The frontend calls this fire-and-forget — it never blocks the restart.
	 * If this fails entirely, startPayment's stale-pending detection will expire
	 * the orphaned row on the next initiation, so correctness is preserved either
	 * way. This method exists to make the invalidation explicit and prompt.
	 *
	 * @param string $reference providerReference (DPO transactionToken /
	 *                          Daraja CheckoutRequestID).
	 * @param string $userId    Authenticated user — ownership is asserted.
	 *
	 * @return PaymentStatus The resulting status (PAID if already completed,
	 *                       otherwise FAILED).
	 *
	 * @throws PaymentNotFoundException if the payment does not exist
	 *
	 * @throws PaymentOwnershipException if the payment is not owned
	 *                          by the user.
	 */
	public function invalidatePayment(string $reference, string $userId): PaymentStatus
	{
		$payment = $this->assertPaymentOwnership($reference, $userId);

		$status = $this->reconciliation->reconcileThenExpire($payment, 'invalidated_by_user');

		$this->logger->info('[Payment] invalidatePayment result', [
			'reference' => $reference,
			'status' => $status->value,
		]);

		return $status;
	}


	/**
	 * Verify payment after redirect (LIGHT CHECK)
	 *
	 * PURPOSE:
	 * - Improve UX after redirect
	 * - Attempt a single verification (optional)
	 *
	 * RULES:
	 * - No retries
	 * - No scheduling
	 * - No locking
	 */
	public function verifyPayment(string $reference): PaymentStatus
	{
		return $this->reconciliation->verifyPayment($reference);
	}


	/**
	 * Synchronise payment status with provider (BACKGROUND JOB ONLY)
	 *
	 * RESPONSIBILITY:
	 * - Calls provider (via VerificationService)
	 * - Updates verification + payment state
	 * - Handles retry scheduling
	 *
	 * RULES:
	 * - Must NOT be called from FE flow
	 * - Only runs for providers that require polling (e.g. DPO)
	 * - Uses locking to avoid concurrent execution
	 *
	 * FLOW:
	 * - Verify with provider
	 * - SUCCESS → finalise payment
	 * - FAILED → mark failed
	 * - PENDING → schedule retry
	 */
	public function syncPaymentStatus(Payment $payment): void
	{
		$this->logger->info('[Payment] syncPaymentStatus start', [
			'paymentId' => $payment->getId(),
			'provider' => $payment->getProvider(),
			'reference' => $payment->getProviderReference(),
			'status' => $payment->getStatus(),
		]);

		$nowImmutable = $this->dateTimeService->nowImmutable();
		$now = $this->dateTimeService->toAtom($nowImmutable);

		try {
			// Safety guard → only polling providers reach here.
			if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
				$payment->unlockVerification();
				$this->paymentMapper->update($payment);
				return;
			}

			// Operational finality boundary — expired payments are abandoned;
			// background reconciliation stops permanently. Marks EXPIRED (not
			// FAILED) so an abandoned poll session is distinguishable in reporting.
			if ($this->validation->hasPaymentExpired($payment)) {
				$this->logger->info('[Payment] payment expired during reconciliation', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				// Release the lock in-memory; markExpired's update() flushes it
				// with the status write (single update, single owner).
				$payment->unlockVerification();

				$auditMeta = $this->metadataHelper->appendProviderError(
					$payment->getProviderMetadataObject(),
					['source' => 'background_verification'],
					$nowImmutable,
				);

				$this->lifecycle->markExpired($payment, 'expired', $auditMeta);
				return;
			}

			// External verification — the ONLY place this happens in the poll loop.
			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$payment->getProviderReference(),
			);

			$this->logger->info('[Payment] verification result', [
				'paymentId' => $payment->getId(),
				'reference' => $payment->getProviderReference(),
				'verificationStatus' => $status,
			]);

			$payment->setVerificationStatus($status);
			$payment->setVerificationLastCheckedAt($now);

			// SUCCESS → finalise. Unlock first so finalisePayment's transaction
			// flushes the lock release atomically with status → PAID + entitlement.
			if ($status === 'SUCCESS') {
				$this->logger->info('[Payment] payment verified successfully', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$payment->unlockVerification();
				$this->lifecycle->finalisePayment($payment);
				return;
			}

			// FAILED → provider confirmed; no more retries.
			if ($status === 'FAILED') {
				$this->logger->warning('[Payment] payment verification failed', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$payment->unlockVerification();
				$this->lifecycle->markFailed($payment, 'generic_failure');
				return;
			}

			// Still pending → schedule a retry if allowed. No status transition,
			// so this stays a direct verification-state update.
			if ($payment->shouldRetry()) {
				$newRetry = $payment->getVerificationRetryCount() + 1;

				$payment->setVerificationRetryCount($newRetry);
				$payment->scheduleNextVerification($newRetry);
				$payment->unlockVerification();
				$payment->setUpdatedAt($now);

				$this->logger->info('[Payment] scheduling retry', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
					'retry' => $newRetry,
					'nextVerificationAt' => $payment->getNextVerificationAt(),
				]);

				$this->paymentMapper->update($payment);

				$this->logger->info('[Payment] dispatching retry verification', [
					'paymentId' => $payment->getId(),
					'retry' => $newRetry,
				]);

				$this->verificationDispatcher->dispatchVerification($payment->getId());
				return;
			}

			// Retries exhausted → fail defensively.
			$payment->unlockVerification();
			$this->lifecycle->markFailed($payment, 'generic_failure');

		} catch (\Throwable $e) {
			$this->logger->error('[VerificationService] verifyStatus failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Capture provider/system failure for debugging (no status change).
			$payment->setLastErrorCode('VERIFY_FAILED');
			$payment->setLastErrorMessage($e->getMessage());
			$payment->setLastErrorAt($now);
			$payment->setUpdatedAt($now);

			$payment->unlockVerification();
			$this->paymentMapper->update($payment);

			throw $e;
		}
	}

	/**
	 * 	Query payment status using provider fallback (Daraja STK query).
	 *  Used as a fallback when the Daraja callback is delayed or missing.
	 *  Triggered by the client after a waiting period (~20 seconds).
	 * 	Only applies to async providers (currently Daraja)
	 */
	public function queryPayment(string $reference): PaymentStatus
	{
		return $this->reconciliation->queryPayment($reference);
	}

	/**
	 * Check if payment is complete
	 */
	public function isPaymentComplete(int $signRequestId): bool
	{
		$payment = $this->paymentMapper->findLatestPaidByTransactionId($signRequestId);

		return $payment !== null;
	}

	/**
	 * Handle Daraja callback
	 *
	 * - Maps CheckoutRequestID → payment
	 * - Ensures idempotency
	 * - Updates status
	 *
	 * Daraja Result codes:
	 * - 0    → SUCCESS
	 * - 1032 → CANCELLED (user cancelled the STK push)
	 * - 1    → FAILED (insufficient funds)
	 * - 1037 → FAILED (timeout — user didn't respond)
	 * - any other non-zero → FAILED
	 */
	public function handleDarajaCallback(array $payload): void
	{
		$reference = $payload['CheckoutRequestID'] ?? null;
		$resultCode = $payload['ResultCode'] ?? null;
		$resultDesc = $payload['ResultDesc'] ?? null;

		$nowImmutable = $this->dateTimeService->nowImmutable();
		$now = $this->dateTimeService->toAtom($nowImmutable);

		// Controller already validates CheckoutRequestID; guard defensively.
		if (!$reference) {
			$this->logger->error('[Daraja Callback] Missing CheckoutRequestID', ['payload' => $payload]);
			return;
		}

		if ($resultCode === null) {
			$this->logger->error('[Daraja Callback] Missing ResultCode', [
				'reference' => $reference,
				'payload' => $payload,
			]);
			return;
		}

		try {
			$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

			$this->logger->info('[Daraja Callback] Received', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			// IDEMPOTENCY GUARD — Daraja may send duplicate callbacks.
			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[Daraja Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return;
			}

			// Store raw callback payload regardless of outcome — audit only,
			// backend-only (providerPayload never serialises to FE).
			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerPayload: $this->metadataHelper->getProviderPayload($meta)->withCallback($payload),
			);
			$payment->setProviderMetadataObject($meta);

			// SUCCESS
			if ((int)$resultCode === 0) {
				$callbackMeta = $this->extractMetadata($payload);

				$this->logger->info('[Daraja Callback] Payment SUCCESS', [
					'reference' => $reference,
					'mpesaReceipt' => $callbackMeta['MpesaReceiptNumber'] ?? null,
				]);

				$payment->setVerificationStatus('SUCCESS');
				$payment->setVerificationLastCheckedAt($now);

				// finalisePayment's txn update flushes the meta + verification
				// fields set above alongside the status write.
				$this->lifecycle->finalisePayment($payment);
				return;
			}

			// CANCELLED (1032) — user dismissed the STK push. Distinct from
			// failure; allows immediate retry.
			if ((int)$resultCode === 1032) {
				$this->logger->info('[Daraja Callback] Payment CANCELLED by user', [
					'reference' => $reference,
					'resultDesc' => $resultDesc,
				]);

				// Stage provider audit only; markCancelled appends the canonical
				// {type,reason,timestamp} and owns the status write.
				$auditMeta = $this->metadataHelper->appendProviderError(
					$meta,
					[
						'resultCode' => $resultCode,
						'resultDesc' => $resultDesc,
						'source' => 'daraja_callback',
					],
					$nowImmutable,
				);

				$this->lifecycle->markCancelled(
					$payment,
					DarajaService::mapResultCodeToReason((int)$resultCode) ?? 'cancelled',
					$auditMeta,
				);
				return;
			}

			// FAILED — any other non-zero code (insufficient funds, timeout, decline).
			$this->logger->warning('[Daraja Callback] Payment FAILED', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
			]);

			$auditMeta = $this->metadataHelper->appendProviderError(
				$meta,
				[
					'resultCode' => $resultCode,
					'resultDesc' => $resultDesc,
					'source' => 'daraja_callback',
				],
				$nowImmutable,
			);

			$this->lifecycle->markFailed(
				$payment,
				DarajaService::mapResultCodeToReason((int)$resultCode) ?? 'generic_failure',
				$auditMeta,
			);
		} catch (\Throwable $e) {
			$this->logger->error('[Daraja Callback] Processing failed', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			throw $e;
		}
	}


	/**
	 * Handle DPO callback
	 *
	 * - Maps TransactionToken → payment
	 * - Ensures idempotency
	 * - Updates status
	 *
	 * DPO Result codes:
	 * - 000 → SUCCESS
	 * - 901 → FAILED (payment failed)
	 * - 902 → FAILED (payment declined)
	 * - 904 → CANCELLED (user cancelled)
	 */
	public function handleDpoCallback(array $payload): ?PaymentStatus
	{
		$reference = $payload['TransactionToken'] ?? null;
		$result = (string)($payload['Result'] ?? '');

		// Controller already validates TransactionToken; guard defensively.
		if (!$reference) {
			$this->logger->error('[DPO Callback] Missing TransactionToken', ['payload' => $payload]);
			return null;
		}

		if ($result === '') {
			$this->logger->error('[DPO Callback] Missing Result code', [
				'reference' => $reference,
				'payload' => $payload,
			]);
			return null;
		}

		$nowImmutable = $this->dateTimeService->nowImmutable();
		$now = $this->dateTimeService->toAtom($nowImmutable);

		try {
			$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

			$this->logger->info('[DPO Callback] Received', [
				'reference' => $reference,
				'result' => $result,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			// IDEMPOTENCY GUARD — may already be resolved by the background job
			// or a duplicate callback.
			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[DPO Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return $payment->getPaymentStatus();
			}

			// Store raw callback payload regardless of outcome — audit only,
			// backend-only.
			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerPayload: $this->metadataHelper->getProviderPayload($meta)->withCallback($payload),
			);
			$payment->setProviderMetadataObject($meta);

			// SUCCESS
			if ($result === '000') {
				$this->logger->info('[DPO Callback] Payment SUCCESS', [
					'reference' => $reference,
					'amount' => $payload['TransactionAmount'] ?? null,
					'currency' => $payload['TransactionCurrency'] ?? null,
				]);

				$payment->setVerificationStatus('SUCCESS');
				$payment->setVerificationLastCheckedAt($now);

				$this->lifecycle->finalisePayment($payment);
				return PaymentStatus::PAID;
			}

			// CANCELLED (904) — user abandoned the flow. Distinct from FAILED;
			// allows immediate retry with no backoff.
			if ($result === '904') {
				$this->logger->info('[DPO Callback] Payment CANCELLED by user', [
					'reference' => $reference,
				]);

				$auditMeta = $this->metadataHelper->appendProviderError(
					$meta,
					[
						'result' => $result,
						'source' => 'dpo_callback',
					],
					$nowImmutable,
				);

				$this->lifecycle->markCancelled($payment, 'cancelled', $auditMeta);
				return PaymentStatus::CANCELLED;
			}

			// FAILED — 901, 902, or any other non-success code.
			$this->logger->warning('[DPO Callback] Payment FAILED', [
				'reference' => $reference,
				'result' => $result,
			]);

			$auditMeta = $this->metadataHelper->appendProviderError(
				$meta,
				[
					'result' => $result,
					'source' => 'dpo_callback',
				],
				$nowImmutable,
			);

			// TODO(missed_window): DPO result-code → leak-safe reason mapper
			$this->lifecycle->markFailed($payment, 'generic_failure', $auditMeta);
			return PaymentStatus::FAILED;
		} catch (\Throwable $e) {
			$this->logger->error('[DPO Callback] Processing failed', [
				'reference' => $reference,
				'result' => $result,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			throw $e;
		}
	}

	/**
	 * Initiate a mobile payment charge for DPO.
	 *
	 * RESPONSIBILITY:
	 * - Triggers the mobile payment request (STK push or instruction-based flow)
	 * - Does NOT confirm payment (confirmation happens via polling → getPaymentStatus)
	 *
	 * FLOW CONTEXT:
	 * - Used only for DPO mobile_direct flow
	 * - Called after startPayment() returns a transaction reference
	 *
	 * BEHAVIOR:
	 * - Validates payment belongs to DPO
	 * - Ensures payment is still PENDING (prevents duplicate charges)
	 * - Delegates to provider (ChargeTokenMobile)
	 * - Persists instructions returned by DPO (for FE display)
	 *
	 * IMPORTANT:
	 * - A successful response does NOT mean payment is complete
	 * - FE must poll getPaymentStatus() to resolve final state
	 *
	 * @throws PaymentInvariantException if:
	 * - payment is not DPO
	 * - payment is not in PENDING state
	 */
	public function chargeMobile(
		string $reference,
		string $phone,
		?string $inputMno = null,
		?string $inputCountry = null
	): ExistingPaymentResultDTO {
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);
		/**
		 * Only DPO supports deferred charge
		 */
		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new PaymentInvariantException('chargeMobile only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $this->buildExistingPaymentResponse($payment);
		}

		$meta = $payment->getProviderMetadataObject();

		if (!$meta) {
			// Unreachable: getProviderMetadataObject() always returns a DTO.
			// Defensive invariant.
			throw new PaymentInvariantException('Missing payment metadata');
		}

		if ($meta->alreadyCharged) {
			return $this->buildExistingPaymentResponse($payment);
		}

		if (
			$payment->getDisplayAmount() === null ||
			$payment->getDisplayCurrency() === null
		) {
			throw new PaymentInvariantException('Missing display pricing information');
		}

		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();

		$suggested = $meta->suggested;
		$selection = $meta->selection;

		$options = $selection->options;
		$hasOptions = !empty($options);

		$mno = null;
		$country = null;

		if ($inputMno && $inputCountry) {
			$mno = strtolower($inputMno);
			$country = strtolower($inputCountry);
		} else {
			$mno = strtolower($suggested->mno ?? '');
			$country = strtolower($suggested->country ?? '');
		}

		/**
		 * STEP 2 — Defensive validation
		 */
		if ($hasOptions) {
			$this->validateOptionsSelection($options, $mno, $country);
		} else {
			if (!$mno || !$country) {
				throw new PaymentValidationException(PaymentErrorCode::INVALID_MNO, 'Missing MNO selection', retryable: true);
			}
		}

		$selected = new SelectedMnoDTO($mno, $country);

		$amount = $this->amountResolver->toMajorUnits(
			$displayAmountMinor,
			$displayCurrency
		);

		/**
		 * Delegate to MobileMoneyService
		 */
		$result = $this->mobileMoneyService->charge(
			new MobileMoneyChargeDTO(
				providerReference: $reference,
				phone: $phone,
				mno: $mno,
				country: $country,
				amount: $amount,
				currency: $displayCurrency,
			)
		);

		/**
		 * STEP 4 — Persist instructions (if any)
		 */
		$providerPayload = $this->metadataHelper->getProviderPayload($meta)
			->withCharge(
				$result->meta['providerPayload']['charge'] ?? []
			);

		$chargeSent = $result->isExecuting() || $result->isReconciling() || $result->isSuccess();

		$meta = $meta->with(
			updatedAt: $this->dateTimeService->nowImmutable(),
			providerPayload: $providerPayload,
			alreadyCharged: $meta->alreadyCharged || $chargeSent,
			selected: $selected,
			instructions: $result->meta['instructions'] ?? 'Check your phone and approve Phone STK Push'
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentMapper->update($payment);

		try {

			$this->verificationDispatcher
				->dispatchVerification(
					$payment->getId()
				);
		} catch (\Throwable $e) {

			$this->logger->error(
				'[Payment] verification dispatch failed',
				[
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
					'error' => $e->getMessage(),
				]
			);
		}

		return $this->buildExistingPaymentResponse($payment);
	}


	/**
	 * Fetch available mobile payment providers (MNOs) for a DPO transaction.
	 *
	 * RESPONSIBILITY:
	 * - Retrieves valid provider/country combinations from DPO
	 * - Enables dynamic provider selection for unsupported or unknown regions
	 *
	 * FLOW CONTEXT:
	 * - Used when MNO cannot be auto-detected (e.g. non-Kenyan numbers)
	 * - Called after startPayment() using the transaction reference
	 *
	 * BEHAVIOR:
	 * - Ensures payment belongs to DPO
	 * - Ensures payment is still PENDING
	 * - Delegates to provider (GetMobilePaymentOptions)
	 *
	 * IMPORTANT:
	 * - Returned options should be treated as the source of truth
	 * - FE must allow user to select one of the returned providers before calling chargeMobile()
	 *
	 * @throws PaymentInvariantException if:
	 * - payment is not DPO
	 * - payment is not in PENDING state
	 *
	 * @throws PaymentValidationException
	 */
	public function getMobileOptions(string $reference, string $country): array
	{
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new PaymentInvariantException('Mobile options only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			throw new PaymentValidationException(
				PaymentErrorCode::ALREADY_RESOLVED,
				'Cannot fetch options for completed payment',
				retryable: false,
			);
		}

		$options = $this->mobileMoneyService->getMobileOptions($reference, $country);

		$meta = $payment->getProviderMetadataObject();

		$meta = $meta->with(
			updatedAt: $this->dateTimeService->nowImmutable(),
			selection: new SelectionDTO(
				required: true,
				options: $options,
				refreshedAt: time()
			)
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentMapper->update($payment);

		return $options;
	}

	public function health(): array
	{
		return [
			'dpo' => $this->mobileMoneyService->testDpo(),
			'daraja' => $this->mobileMoneyService->testDaraja(),
		];
	}

	/**
	 * Extract Daraja metadata into key-value map
	 */
	private function extractMetadata(array $callback): array
	{

		$items = $callback['CallbackMetadata']['Item'] ?? [];

		$result = [];

		foreach ($items as $item) {
			$name = $item['Name'] ?? null;
			$value = $item['Value'] ?? null;

			if ($name) {
				$result[$name] = $value;
			}
		}

		return $result;
	}

	/**
	 * Specific for deferred charge step in this case (DPO)
	 */
	private function validateOptionsSelection(array $options, string $mno, string $country): void
	{
		foreach ($options as $option) {
			$optionMno = is_string($option['provider'] ?? null)
				? strtolower($option['provider'])
				: null;

			$optionCountry = is_string($option['country'] ?? null)
				? strtolower($option['country'])
				: null;

			if (
				$optionMno === strtolower($mno) &&
				$optionCountry === strtolower($country)
			) {
				return;
			}
		}

		throw new PaymentValidationException(PaymentErrorCode::INVALID_MNO, 'Invalid MNO selection', retryable: true);
	}

	/**
	 * Verify that the authenticated user owns the payment identified by the provider reference.
	 *
	 * @throws PaymentNotFoundException if the payment does not exist
	 * @throws PaymentOwnershipException if the user is not the owner of the payment
	 */
	public function assertPaymentOwnership(string $reference, string $userId): Payment
	{
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		$paymentUserId = $payment->getUserId();
		if ($paymentUserId !== null && $paymentUserId !== $userId) {
			throw new PaymentOwnershipException();
		}

		return $payment;
	}

	private function buildExistingPaymentResponse(Payment $payment): ExistingPaymentResultDTO
	{
		$meta = $payment->getProviderMetadataObject();

		$status = $payment->getPaymentStatus();

		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();

		$displayAmount = null;
		$displayAmountFormatted = null;

		/**
		 * Convert stored minor units → major units for API response.
		 *
		 * IMPORTANT:
		 * - DB stores minor units
		 * - FE consumes major/display values
		 */
		if ($displayAmountMinor !== null && $displayCurrency !== null) {

			$displayAmount = $this->amountResolver->toMajorUnits(
				$displayAmountMinor,
				$displayCurrency
			);

			$displayAmountFormatted = $this->amountResolver->format(
				$displayAmountMinor,
				$displayCurrency
			);
		}

		return new ExistingPaymentResultDTO(
			updatedAt: $payment->getUpdatedAtImmutable(),
			paymentId: $payment->getId(),
			signRequestId: $payment->getTransactionId(),
			signUuid: $payment->getTransactionReference(),
			reference: $payment->getProviderReference(),

			// Domain → API status mapping
			status: $this->mapPaymentStatus($status),

			provider: $payment?->getPaymentProvider(),

			// Safe enum mapping
			flow: $meta?->flow
				? PaymentFlow::tryFrom($meta->flow) ?? PaymentFlow::UNKNOWN
				: PaymentFlow::UNKNOWN,

			// Safe method mapping
			method: $meta?->method
				? PaymentMethod::tryFrom($meta->method) ?? null
				: null,

			// Provider + UX data
			redirectUrl: $meta?->redirectUrl,
			instructions: $meta?->instructions,

			// MNO context
			mno: $meta?->suggested?->mno,
			country: $meta?->suggested?->country,
			alreadyCharged: $meta?->alreadyCharged,
			providerExecutionState: $meta?->providerExecutionState ?? ProviderExecutionState::UNKNOWN,
			selected: $meta?->selected,
			confidence: $meta?->confidence,

			// Selection handling
			requiresProviderSelection: $meta?->selection?->required ?? false,
			options: $meta?->selection?->options ?? [],

			// Phone (nullable)
			phoneNumber: $payment->getPhoneE164Digits(),
			phoneNumberRegion: $payment->getPhoneRegion(),
			phoneNumberCountry: $payment->getPhoneCountry(),

			// Pricing + FX
			displayAmount: $displayAmount, // major units
			displayAmountFormatted: $displayAmountFormatted,
			displayCurrency: $displayCurrency,

			paymentPurpose: $payment->getPaymentPurpose(),
			quantity: $payment->getQuantity(),
			unitAmount: $payment->getUnitAmount(),
		);
	}

	public function mapPaymentStatus(PaymentStatus $status): string
	{
		return match ($status) {
			PaymentStatus::PAID => 'SUCCESS',
			PaymentStatus::INITIATION_FAILED => 'INITIATION_FAILED',
			PaymentStatus::EXPIRED => 'EXPIRED',
			PaymentStatus::CANCELLED => 'CANCELLED',
			PaymentStatus::FAILED => 'FAILED',
			default => 'PENDING',
		};
	}

	/**
	 * Resolve a safe, user-facing failure reason key for a payment.
	 *
	 * Raw provider descriptions and internal error details are NOT returned;
	 * only a curated machine-readable key that the frontend can map to a
	 * localised message. This prevents data leakage / harvesting of provider
	 * error text, subscriber identifiers, or system internals.
	 */
	public function getPaymentFailureReason(Payment $payment): ?string
	{
		$status = $payment->getPaymentStatus();

		if (!in_array($status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)) {
			return null;
		}

		$meta = $payment->getProviderMetadataObject();
		$reason = $meta->providerError['reason'] ?? null;

		if (is_string($reason) && $reason !== '') {
			return $reason;
		}

		return match ($status) {
			PaymentStatus::CANCELLED => 'cancelled',
			PaymentStatus::EXPIRED => 'expired',
			PaymentStatus::FAILED => 'generic_failure',
			default => null,
		};
	}

	/**
	 * Validate the minimum provider response contract required to continue
	 * payment processing.
	 *
	 * @throws RuntimeException When the provider response is incomplete.
	 */
	private function validateProviderResult(
		MobileMoneyResultDTO | CardPaymentResultDTO $result
	): void
	{
		if ($result->providerReference === null || $result->providerReference === '') {
			throw new RuntimeException('Provider response missing provider reference.');
		}

		if ($result->provider === null) {
			throw new RuntimeException('Provider response missing provider.');
		}

		if ($result->flow === null) {
			throw new RuntimeException('Provider response missing payment flow.');
		}
	}

	/**
	 * Validate that an initiated payment is internally consistent
	 * before returning it to the frontend.
	 *
	 * This is a defensive invariant check performed after provider
	 * execution and persistence. If the payment is missing any
	 * required orchestration state, initiation is considered to
	 * have failed and the payment is downgraded to
	 * INITIATION_FAILED.
	 *
	 * @throws RuntimeException
	 */
	private function validateInitiatedPayment(
		Payment $payment,
	): void {
		if (!$payment->getProviderReference()) {
			throw new RuntimeException('Payment is missing provider reference.');
		}

		$metadata = $payment->getProviderMetadataObject();

		if ($metadata === null) {
			throw new RuntimeException('Payment is missing provider metadata.');
		}

		if ($metadata->flow === null || $metadata->flow === '') {
			throw new RuntimeException('Payment is missing payment flow.');
		}

		if ($payment->getPaymentProvider() === null) {
			throw new RuntimeException('Payment is missing executed provider.');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			throw new RuntimeException(sprintf(
				'Expected payment status "%s", got "%s".',
				PaymentStatus::PENDING->value,
				$payment->getPaymentStatus()->value,
			));
		}
	}
}
