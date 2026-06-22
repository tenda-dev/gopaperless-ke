<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentFlow;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Payment\Dispatchers\VerificationDispatcherFactory;
use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\DTO\SuggestedMnoDTO;
use OCA\Libresign\Service\Product\ProductService;
use OCP\DB\Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
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
	/**
	 * Operational payment expiry window.
	 *
	 * IMPORTANT:
	 * - Mobile money/Card checkouts are short-lived UX flows
	 * - Expiry defines reconciliation finality
	 * - Expired payments stop background verification
	 *
	 * Typical payment completion:
	 * - 30s → 5 mins
	 * - >15 mins usually abandoned
	 */
	private const PAYMENT_EXPIRY_SECONDS = 15 * 60; // 15 minutes

	private PaymentMapper $paymentMapper;
	private LoggerInterface $logger;
	protected ProductService $productService;
	protected AmountResolver $amountResolver;
	protected EntitlementService $entitlementService;
	protected IDBConnection $db;
	protected PhoneResolutionService $phoneResolutionService;
	protected MnoRoutingRegistry $mnoRoutingRegistry;
	protected MobileMoneyService $mobileMoneyService;
	protected CardService $cardService;
	protected MnoDetectionRegistry $mnoDetectionRegistry;
	protected VerificationService $verificationService;
	protected PaymentCountryResolver $countryResolver;
	protected FxEngineService $fxEngineService;
	protected ProviderAmountNormaliser $providerAmountNormaliser;
	private VerificationDispatcherFactory $verificationDispatcher;

	public function __construct(
		PaymentMapper $paymentMapper,
		LoggerInterface $logger,
		ProductService $productService,
		AmountResolver $amountResolver,
		EntitlementService $entitlementService,
		IDBConnection $db,
		PhoneResolutionService $phoneResolutionService,
		MnoRoutingRegistry $routingRegistry,
		MobileMoneyService $mobileMoneyService,
		CardService $cardService,
		MnoDetectionRegistry $mnoDetectionRegistry,
		VerificationService $verificationService,
		PaymentCountryResolver $countryResolver,
		FxEngineService $fxEngineService,
		ProviderAmountNormaliser $providerAmountNormaliser,
		VerificationDispatcherFactory $verificationDispatcher,
	) {
		$this->paymentMapper = $paymentMapper;
		$this->logger = $logger;
		$this->productService = $productService;
		$this->amountResolver = $amountResolver;
		$this->entitlementService = $entitlementService;
		$this->db = $db;
		$this->phoneResolutionService = $phoneResolutionService;
		$this->mnoRoutingRegistry = $routingRegistry;
		$this->mobileMoneyService = $mobileMoneyService;
		$this->cardService = $cardService;
		$this->mnoDetectionRegistry = $mnoDetectionRegistry;
		$this->verificationService = $verificationService;
		$this->countryResolver = $countryResolver;
		$this->fxEngineService = $fxEngineService;
		$this->providerAmountNormaliser = $providerAmountNormaliser;
		$this->verificationDispatcher = $verificationDispatcher;
	}

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
	public function startPayment(StartPaymentDTO $dto): StartPaymentResultDTO | ExistingPaymentResultDTO
	{
		$userEmail = $dto->userEmail;
		$signUuid = $dto->signUuid;
		$signRequestId = $dto->signRequestId;
		$redirectUrl = $dto->redirectUrl;
		$userId = $dto->userId;
		$productCode = $dto->productCode;
		$callbackUrl = $dto->callbackUrl;
		$paymentAttemptId = $dto->paymentAttemptId;
		$phoneNumber = $dto->phoneNumber;
		$method = $dto->paymentMethod;
		$paymentPurpose = PaymentPurpose::tryFrom($dto->purpose->value) ?? PaymentPurpose::SIGN_REQUEST;
		$quantity = $dto->quantity ?? 1;

		$e164 = null;
		$route = null;
		$ctxMetadata = [];
		$countryCtx = null;
		$fxEngineResult = null;
		$pending = null;

		/**
		 * CORE VALIDATION
		 */
		if (!$userId) {
			throw new RuntimeException('userId is required');
		}

		if (!$productCode) {
			throw new RuntimeException('productCode is required');
		}

		$methodEnum = $method;

		if ($methodEnum === null) {
			throw new RuntimeException('Invalid payment method');
		}

		$capability = match ($methodEnum) {
			PaymentMethod::MOBILE => PaymentCapability::MOBILE_MONEY,
			PaymentMethod::CARD => PaymentCapability::CARD,
		};

		/**
		 * MOBILE FLOW RESOLUTION → DETECTION → ROUTING
		 */
		if ($capability === PaymentCapability::MOBILE_MONEY) {

			if (!$phoneNumber) {
				throw new RuntimeException('Phone number is required');
			}

			$this->validatePhoneNumber($phoneNumber);

			$resolutionDto = $this->phoneResolutionService->resolve($phoneNumber);
			$region = $resolutionDto->region;

			if (!$resolutionDto->valid || !$region) {
				throw new RuntimeException('Unable to resolve phone number');
			}

			if (!$this->mnoRoutingRegistry->supportsRegion($region)) {
				throw new RuntimeException(sprintf(
					'Unsupported region: %s. Supported regions: %s',
					$region,
					implode(', ', $this->mnoRoutingRegistry->supportedRegions())
				));
			}

			// Changed to e164 with the + (persisted - remove the + per provider if needed)
			$e164 = $resolutionDto->e164;

			$countryCtx = $this->countryResolver->resolve($region);

			if (!$countryCtx) {
				throw new RuntimeException('Unsupported country');
			}

			$detection = $this->mnoDetectionRegistry->resolve(
				$region,
				$resolutionDto->national,
			);

			$finalCarrier    = $detection['mno'] ?? $resolutionDto->carrierHint;

			$finalConfidence = $detection['confidence'];

			$route = $this->mnoRoutingRegistry->route(
				$capability,
				$countryCtx->country,
				$region,
				$finalCarrier,
				$finalConfidence
			);

			if (!$route->capability) {
				throw new RuntimeException('Unable to determine payment route');
			}

			$ctxMetadata = [
				'confidenceBreakdown' => $finalConfidence,
				'carrier'             => $finalCarrier,
				'region'              => $region,
			];

			$this->logger->info('Mobile money routing result', [
				'country' => $countryCtx->country,
				'region' => $region,
				'carrier' => $finalCarrier,
				'confidenceBreakdown' => $finalConfidence,
			]);
		}

		/**
		 * CARD FLOW ROUTING
		 */
		if ($capability === PaymentCapability::CARD) {

			if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
				throw new RuntimeException('Valid redirect URL required');
			}

			$route = $this->mnoRoutingRegistry->route(
				$capability,
				null,
				null,
				null,
				ResolutionConfidence::HIGH
			);
		}

		/**
		 * Frontend provider hint is advisory only.
		 *
		 * The backend MNO routing registry is the authoritative source of truth.
		 * We only honor the hint when the backend route itself is uncertain
		 * (ambiguous carrier or missing MNO key), e.g. an unknown Kenyan prefix
		 * that the frontend happens to recognise as Safaricom.
		 *
		 * This prevents a stale/wrong frontend hint from overriding a
		 * high-confidence backend route such as KE Safaricom → Daraja.
		 */
		if (
			$dto->provider !== null
			&& $capability === PaymentCapability::MOBILE_MONEY
			&& $route->requiresUserSelection()
		) {
			$route = $route->withPreferredProvider($dto->provider);
			$this->logger->info('Using frontend provider hint for uncertain route', [
				'hint' => $dto->provider->value,
				'route_confidence' => $route->confidence->value,
			]);
		} elseif ($dto->provider !== null && $capability === PaymentCapability::MOBILE_MONEY) {
			$this->logger->info('Ignoring frontend provider hint; backend route is authoritative', [
				'hint' => $dto->provider->value,
				'route_provider' => $route->preferredProvider->value,
				'route_confidence' => $route->confidence->value,
			]);
		}

		$this->logger->info('Starting payment', [
			'sign_uuid' => $signUuid,
			'sign_request_id' => $signRequestId,
			'capability' => $capability->value,
			'method' => $methodEnum->value,
			'purpose' => $paymentPurpose->value,
			'route_provider' => $route?->preferredProvider?->value ?? null,
			'route_confidence' => $route?->confidence?->value ?? null,
			'route_mno_key' => $route?->mnoKey ?? null,
		]);

		/**
		 * DUPLICATE AND IDEMPOTENCY GUARDS
		 */
		if ($paymentPurpose === PaymentPurpose::SIGN_REQUEST) {

			if ($signRequestId === null) {
				throw new RuntimeException('signRequestId is required');
			}

			if ($signUuid === null || $signUuid === '') {
				throw new RuntimeException('signUuid is required');
			}

			if ($this->paymentMapper->findLatestPaidByTransactionId($signRequestId)) {
				throw new RuntimeException('Payment already completed');
			}

			$pending = $this->paymentMapper
				->findLatestPendingByTransactionId($signRequestId);
		} else {

			$pending = $this->paymentMapper
				->findLatestPendingCreditPurchaseByUserId(
					$userId,
					$productCode
				);
		}

		if ($pending) {
			$pending = $this->paymentMapper->findById($pending->getId());

			if (!$this->hasPaymentExpired($pending)) {

				$meta = $pending->getProviderMetadataObject();

				/**
				 * Routing may have changed since the pending payment
				 * was created (e.g. KE Safaricom fix switched from
				 * DPO to Daraja). Do not recycle a stale route.
				 */
				if (
					$route !== null &&
					$pending->getProviderEnum() !== $route->preferredProvider
				) {

					$this->logger->info('[Payment] Expiring pending payment because route provider changed', [
						'pending_provider' => $pending->getProviderEnum()->value,
						'route_provider' => $route->preferredProvider->value,
						'payment_id' => $pending->getId(),
					]);

					$this->expirePayment($pending);
				} elseif ($meta->method !== $methodEnum->value) {

					$this->expirePayment($pending);
				} elseif (
					$methodEnum === PaymentMethod::MOBILE &&
					$e164 !== null &&
					$pending->getPhoneE164Digits() !== null &&
					$pending->getPhoneE164Digits() !== $e164
				) {

					$this->expirePayment($pending);
				} else {

					return $this->buildExistingPaymentResponse($pending);
				}
			}

			$this->expirePayment($pending);

			$this->logger->info('[Payment] Expired pending payment', [
				'purpose' => $paymentPurpose->value,
				'sign_request_id' => $signRequestId,
				'user_id' => $userId,
				'product_code' => $productCode,
			]);
		}

		if (!$paymentAttemptId) {
			$paymentAttemptId = Uuid::uuid4()->toString();
		}

		$existing = $this->paymentMapper->findByAttemptId($paymentAttemptId);

		if ($existing) {
			$existing = $this->paymentMapper->findById($existing->getId());
			return $this->buildExistingPaymentResponse($existing);
		}

		/**
		 * PRODUCT AND AMOUNT RESOLUTION
		 */
		$product = $this->productService->getDefaultByCode($productCode);

		$totalUses = $product->getUses() * $quantity;
		$unitAmount = $product->getAmount();
		$totalAmount = $unitAmount * $quantity;

		$amountMinor = $totalAmount;
		$currency    = $product->getCurrency();
		$uses        = $totalUses;

		if ($uses <= 0) {
			throw new RuntimeException('Invalid product configuration');
		}

		/**
		 * IMPORTANT: FX CONVERSION MUST HAPPEN BEFORE PAYMENT CREATION AND PROVIDER CALL
		 * Currency used for pricing this payment.
		 *
		 * - DPO mobile → local currency (FX applied)
		 * - Daraja → KES (identity FX)
		 * - Card → handled by provider (bypass FX)
		 */
		$targetCurrency = match (true) {
			$route->preferredProvider === PaymentProvider::DARAJA => 'KES',
			$route->capability === PaymentCapability::CARD => null,
			default => $countryCtx->currency,
		};

		if ($targetCurrency !== null) {
			$fxEngineResult = $this->fxEngineService->convert(
				kesAmount: $amountMinor,
				targetCurrency: $targetCurrency,
			);
		} else {
			// CARD fallback (no FX)
			$fxEngineResult = $this->fxEngineService->identityResult($amountMinor);
		}

		/**
		 * CREATE PAYMENT BEFORE PROVIDER CALL
		 */
		$payment = new Payment();
		$payment->setPaymentAttemptId($paymentAttemptId);
		$payment->setPaymentPurpose($paymentPurpose);
		$payment->setTransactionId($signRequestId);
		$payment->setTransactionReference($signUuid);
		$payment->setAmount($amountMinor);
		$payment->setCurrency($currency);
		$payment->setUserId($userId);
		$payment->setPhoneE164Digits($e164);
		$payment->setPhoneRegion($route?->region);
		$payment->setPhoneCountry($route?->country);
		$payment->setProductCode($productCode);
		$payment->setProductUses($uses);
		$payment->setQuantity($quantity);
		$payment->setUnitAmount($unitAmount);
		$payment->setPaymentStatus(PaymentStatus::PENDING);
		$payment->setProvider($route->preferredProvider->value);
		$payment->setCreatedAt($this->now());

		// FXEngine result
		$payment->setDisplayAmount($fxEngineResult->displayAmount);
		$payment->setDisplayCurrency($fxEngineResult->displayCurrency);
		$payment->setFxRate($fxEngineResult->fxRate);
		$payment->setFxRateSource($fxEngineResult->fxRateSource);
		$payment->setFxRateLockedAt($fxEngineResult->fxRateLockedAt->format(DATE_ATOM));

		$payment->validate();
		$this->paymentMapper->insert($payment);

		$normalisedAmount = $this->providerAmountNormaliser->normalise(
			$fxEngineResult->displayAmount,
			$fxEngineResult->displayCurrency,
			$route->preferredProvider
		);

		/**
		 * EXECUTE VIA CAPABILITY SERVICE
		 */
		try {

			$res = match ($route->capability) {

				PaymentCapability::MOBILE_MONEY =>
				$this->mobileMoneyService->initiate(
					$route,
					new MobileMoneyPayloadDTO(
						phone: $e164,
						amount: $normalisedAmount,
						currency: $fxEngineResult->displayCurrency,
						signUuid: $signUuid,
						signRequestId: $signRequestId,
						userId: $userId,
						email: $userEmail,
						callbackUrl: $callbackUrl,
						redirectUrl: $redirectUrl,
					)
				),

				PaymentCapability::CARD =>
				$this->cardService->initiateCard(
					new CardPaymentPayloadDTO(
						amount: $normalisedAmount,
						currency: $fxEngineResult->displayCurrency,
						signUuid: $signUuid,
						signRequestId: $signRequestId,
						userId: $userId,
						email: $userEmail,
						redirectUrl: $redirectUrl,
						callbackUrl: $callbackUrl,
					)
				),

				default => throw new RuntimeException('Unsupported capability'),
			};
		} catch (\Throwable $e) {

			$this->logger->error('Payment initiation failed', [
				'error' => $e->getMessage(),
				'attempt' => $paymentAttemptId,
				'trace' => $e->getTrace(),
			]);

			$payment->setPaymentStatus(PaymentStatus::INITIATION_FAILED);

			$meta = $payment->getProviderMetadataObject();

			$meta = $meta->with(
				providerExecutionState: ProviderExecutionState::FAILED,
				updatedAt: $this->nowImmutable(),
				providerError: [
					'message' => $e->getMessage(),
					'provider' => $route?->preferredProvider?->value ?? 'unknown',
					'timestamp' => time(),
				]
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentMapper->update($payment);

			throw $e;
		}

		/**
		 * VALIDATE PROVIDER RESPONSE
		 */
		if (!$res->providerReference || !$res->flow) {
			$message = 'Invalid provider response: ' . ($res->message ?? 'empty result');

			$this->logger->error('Payment provider response invalid', [
				'error' => $message,
				'attempt' => $paymentAttemptId,
			]);

			$payment->setPaymentStatus(PaymentStatus::INITIATION_FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerExecutionState: ProviderExecutionState::FAILED,
				updatedAt: $this->nowImmutable(),
				providerError: [
					'message' => $message,
					'provider' => $route?->preferredProvider?->value ?? 'unknown',
					'timestamp' => time(),
				]
			);
			$payment->setProviderMetadataObject($meta);
			$this->paymentMapper->update($payment);

			throw new RuntimeException($message);
		}

		/**
		 * PERSIST REFERENCE AND METADATA
		 */
		$payment->setPaymentProvider($res->provider);
		$payment->setProviderReference($res->providerReference);

		$metaPayload = $res->meta ?? [];

		if (!is_array($metaPayload)) {
			$metaPayload = [];
		}

		$selected = new SelectedMnoDTO(null, null);

		$selection = isset($metaPayload['selection']) && $metaPayload['selection'] instanceof SelectionDTO
			? $metaPayload['selection']
			: new SelectionDTO(false, []);

		$suggested = isset($metaPayload['suggested']) && $metaPayload['suggested'] instanceof SuggestedMnoDTO
			? $metaPayload['suggested']
			: new SuggestedMnoDTO(null, null);

		$metadata = new PaymentMetadataDTO(
			updatedAt: $this->nowImmutable(),
			preferredProvider: $route->preferredProvider->value,
			executedProvider: $res->provider->value,
			flow: $res->flow->value,
			method: $methodEnum->value,
			redirectUrl: $res instanceof CardPaymentResultDTO ? $res->redirectUrl : null,
			selected: $selected,
			suggested: $suggested,
			selection: $selection,
			confidence: $metaPayload['confidence'] ?? $route->confidence->value,
			alreadyCharged: $res->providerExecutionState->hasExecutionStarted(),
			instructions: $metaPayload['instructions'] ?? null,
			context: $ctxMetadata,
			providerExecutionState: $res->providerExecutionState,
			providerPayload: ProviderPayloadDTO::fromArray(
				$metaPayload['providerPayload'] ?? []
			),
			providerError: null,
		);

		$payment->setProviderMetadataObject($metadata);
		$this->paymentMapper->update($payment);


		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();
		$displayAmount = null;
		$displayAmountFormatted = null;

		/**
		 * Convert stored minor units example KES 1000 cents → major units KES 10.00 for API response.
		 *
		 * IMPORTANT:
		 * - DB stores minor units
		 * - FE consumes major/display FLOAT and STRING (formatted) values
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

		/**
		 * RESPONSE TO FRONTEND
		 */
		return new StartPaymentResultDTO(
			updatedAt: $payment->getUpdatedAtImmutable(),
			paymentId: $payment->getId(),
			signRequestId: $payment->getTransactionId(),
			signUuid: $payment->getTransactionReference(),
			reference: $res->providerReference,
			provider: $res->provider,
			flow: $res->flow,
			method: $methodEnum,
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
	 * Check Payment Status
	 *
	 * - Daraja → already handled via callback
	 * - DPO → requires API verification, must be polled, if handling mobile_direct flow
	 * @throws RuntimeException
	 * @throws \Throwable
	 */
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
		$payment = $this->fetchPaymentByProviderReference($reference);

		// Fast path → already resolved
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		// Handle expiration locally (no provider call needed)
		if ($this->hasPaymentExpired($payment)) {

			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();

			$meta = $meta->with(
				providerError: [
					'type' => 'expired',
					'timestamp' => $this->now(),
				]
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentMapper->update($payment);

			return PaymentStatus::FAILED;
		}

		// IMPORTANT:
		// Do NOT call verificationService here.
		// Status is updated asynchronously by the background job.
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
	 * @throws RuntimeException
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
		 * SIGN REQUEST OWNERSHIP VALIDATION
		 *
		 * Prevents:
		 * - arbitrary payment hydration
		 * - stale cross-user restoration
		 * - leaking payment metadata
		 */
		if (
			$purpose === PaymentPurpose::SIGN_REQUEST &&
			$payment->getTransactionReference() !== $signUuid
		) {
			return null;
		}

		/**
		 * Authenticated ownership validation
		 *
		 * External signers may not have a userId,
		 * so only enforce when present.
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
		if ($this->hasPaymentExpired($payment)) {
			$this->expirePayment($payment);
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
	 * - Background job remains source of truth
	 */
	public function verifyPayment(string $reference): PaymentStatus
	{
		$payment = $this->fetchPaymentByProviderReference($reference);

		// Already resolved → return immediately
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		// Daraja → handled via callback only
		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			return $payment->getPaymentStatus();
		}

		try {

			// One-shot verification (UX boost)
			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$reference
			);

			if ($status === 'SUCCESS') {

				$this->finalisePayment($payment);
				return PaymentStatus::PAID;
			}

			if ($status === 'FAILED') {

				$payment->setUpdatedAt($this->now());
				$payment->setPaymentStatus(PaymentStatus::FAILED);
				$this->paymentMapper->update($payment);

				return PaymentStatus::FAILED;
			}

			// Still pending → let background job handle it
			return PaymentStatus::PENDING;
		} catch (\Throwable $e) {

			// Do NOT fail hard → background job will retry
			$this->logger->warning('[Payment] verifyPayment failed (non-blocking)', [
				'reference' => $reference,
				'error' => $e->getMessage()
			]);

			return PaymentStatus::PENDING;
		}
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
	public function syncPaymentStatus(
		Payment $payment
		): void
	{
		$this->logger->info('[Payment] syncPaymentStatus start', [
			'paymentId' => $payment->getId(),
			'provider' => $payment->getProvider(),
			'reference' => $payment->getProviderReference(),
			'status' => $payment->getStatus(),
		]);

		try {

			// Safety guard → only polling providers should reach here
			if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
				$payment->unlockVerification();
				$this->paymentMapper->update($payment);
				return;
			}

			/**
			 * Operational finality boundary.
			 *
			 * IMPORTANT:
			 * - Expired payments are considered abandoned
			 * - Background reconciliation must stop permanently
			 * - Prevents stale pending payments
			 */
			if ($this->hasPaymentExpired($payment)) {

				$this->logger->info('[Payment] payment expired during reconciliation', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$payment->setPaymentStatus(PaymentStatus::EXPIRED);

				$meta = $payment->getProviderMetadataObject();

				$providerError = [
					'type' => 'expired',
					'source' => 'background_verification',
					'timestamp' => $this->now(),
				];

				if ($meta) {
					$payment->setUpdatedAt($this->now());
					$meta = $this->appendProviderError($meta, $providerError);
					$payment->setProviderMetadataObject($meta);
				}

				$payment->unlockVerification();

				$this->paymentMapper->update($payment);

				return;
			}


			// External verification (ONLY place this happens)
			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$payment->getProviderReference()
			);

			$this->logger->info('[Payment] verification result', [
				'paymentId' => $payment->getId(),
				'reference' => $payment->getProviderReference(),
				'verificationStatus' => $status,
			]);

			$payment->setVerificationStatus($status);
			$payment->setVerificationLastCheckedAt($this->now());

			if ($status === 'SUCCESS') {

				$this->logger->info('[Payment] payment verified successfully', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				// Payment confirmed → transition to PAID
				$this->finalisePayment($payment);
				$payment->unlockVerification();

				$this->paymentMapper->update($payment);
				return;
			}

			if ($status === 'FAILED') {

				$this->logger->warning('[Payment] payment verification failed', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				// Provider confirmed failure → no more retries
				$payment->setUpdatedAt($this->now());
				$payment->setPaymentStatus(PaymentStatus::FAILED);
				$payment->unlockVerification();

				$this->paymentMapper->update($payment);
				return;
			}

			// Still pending → schedule retry if allowed
			if ($payment->shouldRetry()) {

				$newRetry = $payment->getVerificationRetryCount() + 1;

				$payment->setVerificationRetryCount($newRetry);
				$payment->scheduleNextVerification($newRetry);
				$payment->unlockVerification();

				$this->logger->info('[Payment] scheduling retry', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
					'retry' => $newRetry,
					'nextVerificationAt' => $payment->getNextVerificationAt(),
				]);

				$payment->setUpdatedAt($this->now());
				$this->paymentMapper->update($payment);

				$this->logger->info(
					'[Payment] dispatching retry verification',
					[
						'paymentId' => $payment->getId(),
						'retry' => $newRetry,
					]
				);

				$this->verificationDispatcher->dispatchVerification(
					$payment->getId()
				);
				return;
			}

			// Retries exhausted → fail defensively
			$payment->setPaymentStatus(PaymentStatus::FAILED);
			$payment->unlockVerification();
			$payment->setUpdatedAt($this->now());

			$this->paymentMapper->update($payment);
		} catch (\Throwable $e) {

			$this->logger->error('[VerificationService] verifyStatus failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Capture provider/system failure for debugging
			$payment->setLastErrorCode('VERIFY_FAILED');
			$payment->setLastErrorMessage($e->getMessage());
			$payment->setLastErrorAt($this->now());
			$payment->setUpdatedAt($this->now());

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
	 * @throws Exception
	 */
	public function queryPayment(string $reference): PaymentStatus
	{

		$payment = $this->fetchPaymentByProviderReference($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DARAJA) {
			return $payment->getPaymentStatus();
		}

		/**
		 * IMPORTANT:
		 * If payment already resolved (callback came in),
		 * do NOT query again or override state.
		 */
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		try {
			$result = $this->verificationService->query(
				$payment->getProviderEnum(),
				$reference
			);

			/**
			 * Store query response for debugging / audit
			 */
			$meta = $payment->getProviderMetadataObject();

			switch ($result['status']) {

				case 'SUCCESS':
					$providerPayload = $this->getProviderPayload($meta)
						->withQuery($result);

					$meta = $meta->with(
						updatedAt: $this->nowImmutable(),
						providerPayload: $providerPayload
					);

					$payment->setProviderMetadataObject($meta);
					$this->finalisePayment($payment);
					return PaymentStatus::PAID;

				case 'FAILED':
					$payment->setPaymentStatus(PaymentStatus::FAILED);
					break;

				case 'CANCELLED':
					$payment->setPaymentStatus(PaymentStatus::CANCELLED);
					break;

				case 'PENDING':
				default:
					return PaymentStatus::PENDING;
			}

			/**
			 * Store query response for debugging / audit on FAILED
			 */
			$providerPayload = $this->getProviderPayload($meta)
				->withQuery($result);

			$meta = $meta->with(
				updatedAt: $this->nowImmutable(),
				providerPayload: $providerPayload
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentMapper->update($payment);

			return $payment->getPaymentStatus();
		} catch (\Throwable $e) {
			/**
			 * CRITICAL:
			 * Query is a fallback mechanism.
			 * If it fails, we MUST NOT:
			 * - break the flow
			 * - mark payment as failed
			 *
			 * Instead:
			 * - log error
			 * - return PENDING
			 * - allow callback to still resolve payment
			 */
			$this->logger->error('Daraja query failed', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			return PaymentStatus::PENDING;
		}
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

		// Controller already validates CheckoutRequestID but we guard defensively
		if (!$reference) {
			$this->logger->error('[Daraja Callback] Missing CheckoutRequestID', [
				'payload' => $payload,
			]);
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
			$payment = $this->fetchPaymentByProviderReference($reference);

			$this->logger->info('[Daraja Callback] Received', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			/**
			 * IDEMPOTENCY GUARD
			 * Daraja may send duplicate callbacks.
			 */
			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[Daraja Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return;
			}

			// Store raw callback payload regardless of outcome — always useful for audit
			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerPayload: $this->getProviderPayload($meta)->withCallback($payload)
			);
			$payment->setProviderMetadataObject($meta);

			/**
			 * SUCCESS
			 */
			if ((int)$resultCode === 0) {
				$metadata = $this->extractMetadata($payload);

				$this->logger->info('[Daraja Callback] Payment SUCCESS', [
					'reference' => $reference,
					'mpesaReceipt' => $metadata['MpesaReceiptNumber'] ?? null,
				]);

				$payment->setVerificationStatus('SUCCESS');
				$payment->setVerificationLastCheckedAt(
					$this->now()
				);

				$this->finalisePayment($payment);
				return;
			}

			/**
			 * CANCELLED (user explicitly dismissed the STK push)
			 * Result 1032 — distinct from failure, allows immediate retry.
			 */
			if ((int)$resultCode === 1032) {
				$this->logger->info('[Daraja Callback] Payment CANCELLED by user', [
					'reference' => $reference,
					'resultDesc' => $resultDesc,
				]);

				$payment->setUpdatedAt($this->now());
				$payment->setPaymentStatus(PaymentStatus::CANCELLED);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					updatedAt: $this->nowImmutable(),
					providerError: [
						'type' => 'cancelled',
						'resultCode' => $resultCode,
						'resultDesc' => $resultDesc,
						'source' => 'daraja_callback',
						'timestamp' => $this->now(),
					]
				);
				$payment->setProviderMetadataObject($meta);

				$this->paymentMapper->update($payment);
				return;
			}

			/**
			 * FAILED (insufficient funds, timeout, declined etc.)
			 * Any non-zero, non-1032 result code.
			 */
			$this->logger->warning('[Daraja Callback] Payment FAILED', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
			]);

			$payment->setUpdatedAt($this->now());
			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				updatedAt: $this->nowImmutable(),
				providerError: [
					'type' => 'failed',
					'resultCode' => $resultCode,
					'resultDesc' => $resultDesc,
					'source' => 'daraja_callback',
					'timestamp' => $this->now(),
				]
			);
			$payment->setProviderMetadataObject($meta);

			$this->paymentMapper->update($payment);
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
	public function handleDpoCallback(array $payload): void
	{
		$reference = $payload['TransactionToken'] ?? null;
		$result = (string)($payload['Result'] ?? '');

		// Controller already validates TransactionToken but we guard defensively
		if (!$reference) {
			$this->logger->error('[DPO Callback] Missing TransactionToken', [
				'payload' => $payload,
			]);
			return;
		}

		if ($result === '') {
			$this->logger->error('[DPO Callback] Missing Result code', [
				'reference' => $reference,
				'payload' => $payload,
			]);
			return;
		}

		try {
			$payment = $this->fetchPaymentByProviderReference($reference);

			$this->logger->info('[DPO Callback] Received', [
				'reference' => $reference,
				'result' => $result,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			/**
			 * IDEMPOTENCY GUARD
			 * Payment may already be resolved by background job or a duplicate callback.
			 */
			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[DPO Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return;
			}

			/**
			 * SUCCESS
			 */
			if ($result === '000') {
				$this->logger->info('[DPO Callback] Payment SUCCESS', [
					'reference' => $reference,
					'amount' => $payload['TransactionAmount'] ?? null,
					'currency' => $payload['TransactionCurrency'] ?? null,
				]);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					providerPayload: $this->getProviderPayload($meta)->withCallback($payload)
				);
				$payment->setProviderMetadataObject($meta);

				$this->finalisePayment($payment);
				return;
			}

			/**
			 * CANCELLED (user explicitly cancelled)
			 * Result 904 — user abandoned the payment flow.
			 * Distinct from FAILED — allows immediate retry with no backoff.
			 */
			if ($result === '904') {
				$this->logger->info('[DPO Callback] Payment CANCELLED by user', [
					'reference' => $reference,
				]);

				$payment->setUpdatedAt($this->now());
				$payment->setPaymentStatus(PaymentStatus::CANCELLED);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					updatedAt: $this->nowImmutable(),
					providerError: [
						'type' => 'cancelled',
						'result' => $result,
						'source' => 'dpo_callback',
						'timestamp' => $this->now(),
					]
				);
				$payment->setProviderMetadataObject($meta);

				$this->paymentMapper->update($payment);
				return;
			}

			/**
			 * FAILED (provider-side: declined, error etc.)
			 * Result 901, 902, or any other non-success code.
			 */
			$this->logger->warning('[DPO Callback] Payment FAILED', [
				'reference' => $reference,
				'result' => $result,
			]);

			$payment->setUpdatedAt($this->now());
			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				updatedAt: $this->nowImmutable(),
				providerError: [
					'type' => 'failed',
					'result' => $result,
					'source' => 'dpo_callback',
					'timestamp' => $this->now(),
				]
			);
			$payment->setProviderMetadataObject($meta);

			$this->paymentMapper->update($payment);
		} catch (\Throwable $e) {
			$this->logger->error('[DPO Callback] Processing failed', [
				'reference' => $reference,
				'result' => $result,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Re-throw error and propagate to controller
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
	 * @throws RuntimeException if:
	 * - payment is not DPO
	 * - payment is not in PENDING state
	 */
	public function chargeMobile(
		string $reference,
		string $phone,
		?string $inputMno = null,
		?string $inputCountry = null
	): ExistingPaymentResultDTO {
		$payment = $this->fetchPaymentByProviderReference($reference);
		/**
		 * Only DPO supports deferred charge
		 */
		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new RuntimeException('chargeMobile only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $this->buildExistingPaymentResponse($payment);
		}

		$meta = $payment->getProviderMetadataObject();

		if (!$meta) {
			throw new RuntimeException('Missing payment metadata');
		}

		if ($meta->alreadyCharged) {
			return $this->buildExistingPaymentResponse($payment);
		}

		if (
			$payment->getDisplayAmount() === null ||
			$payment->getDisplayCurrency() === null
		) {
			throw new RuntimeException('Missing display pricing information');
		}

		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();

		$suggested = $meta->suggested;
		$selection = $meta->selection;

		$options = $selection->options;
		$hasOptions = !empty($options);
		$requiresSelection = $selection->required;

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
				throw new RuntimeException('Missing MNO selection');
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
		$providerPayload = $this->getProviderPayload($meta)
			->withCharge(
				$result->meta['providerPayload']['charge'] ?? []
			);

		$chargeSent = $result->isExecuting() || $result->isReconciling() || $result->isSuccess();

		$meta = $meta->with(
			updatedAt: $this->nowImmutable(),
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
	 * @throws RuntimeException if:
	 * - payment is not DPO
	 * - payment is not in PENDING state
	 */
	public function getMobileOptions(string $reference, string $country): array
	{
		$payment = $this->fetchPaymentByProviderReference($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new RuntimeException('Mobile options only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			throw new RuntimeException('Cannot fetch options for completed payment');
		}

		$options = $this->mobileMoneyService->getMobileOptions($reference, $country);

		$meta = $payment->getProviderMetadataObject();

		$meta = $meta->with(
			updatedAt: $this->nowImmutable(),
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

	private function validatePhoneNumber(string $phone): void
	{
		$phoneUtil = PhoneNumberUtil::getInstance();

		// Enforce international format strictly
		if (!str_starts_with($phone, '+')) {
			throw new \InvalidArgumentException(
				'Phone number must be in international format'
			);
		}

		try {
			$parsed = $phoneUtil->parse($phone, null);

			if (!$phoneUtil->isValidNumber($parsed)) {
				throw new \InvalidArgumentException(
					'The provided phone number is not valid.'
				);
			}

			// Optional (disabled for now):
			// $supportedRegions = ['KE', 'UG', 'TZ'];
			// $region = $phoneUtil->getRegionCodeForNumber($parsed);
			// if (!in_array($region, $supportedRegions, true)) {
			//     throw new \InvalidArgumentException("We do not support numbers from $region yet.");
			// }

		} catch (NumberParseException) {
			throw new \InvalidArgumentException(
				'Invalid phone number. Use international format (e.g., +254...)'
			);
		}
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
	 * @throws \Throwable
	 * @throws Exception
	 */
	private function finalisePayment(Payment $payment): void
	{

		/**
		 * IDEMPOTENCY GUARD
		 *
		 * Payment providers may send duplicate callbacks.
		 * If already processed, exit early.
		 */
		if ($payment->getPaymentStatus() === PaymentStatus::PAID) {
			return;
		}

		/**
		 * CRITICAL TRANSACTION
		 *
		 * Ensures atomic consistency between:
		 * - Payment status update (financial state)
		 * - Entitlement creation (access state)
		 *
		 * MUST NOT partially succeed.
		 */
		$this->db->beginTransaction();

		try {
			$this->logger->info('[PAYMENT FINALISE] Before update', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
			]);
			$payment->setUpdatedAt($this->now());
			$payment->setPaymentStatus(PaymentStatus::PAID);
			$payment->setPaidAt($this->now());

			$this->paymentMapper->update($payment);

			/**
			 * Create entitlement ONCE per successful payment
			 */

			$this->entitlementService->create(
				$payment->getUserId(),
				$payment->getProductCode(), // TODO: derive from product_code later
				$payment->getProductUses()
			);

			$this->db->commit();

			$this->logger->info('[PAYMENT FINALISE] After update call', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
			]);
		} catch (\Throwable $e) {

			/**
			 * Rollback ALL changes to prevent partial state
			 */
			$this->db->rollBack();

			$this->logger->error('[PAYMENT FINALISE] failed', [
				'exception' => $e,
				'paymentId' => $payment->getId(),
			]);

			throw $e;
		}
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

		throw new RuntimeException('Invalid MNO selection');
	}

	/**
	 * Get Payment entity - ENTITY REHYDRATION (Nextcloud quirk)
	 *
	 * Re-fetching ensures a fully hydrated entity after initial lookup.
	 *
	 * In some cases, partial hydration or stale state may occur,
	 * especially when mixing reads and updates within the same flow.
	 *
	 * This guarantees a clean, fully mapped entity before mutation.
	 * also ensures $payment is a managed entity
	 */
	private function fetchPaymentByProviderReference(string $reference): Payment
	{
		$payment = $this->paymentMapper->findByProviderReference($reference);

		if (!$payment) {
			$this->logger->error('[PaymentService] Payment not found', [
				'reference' => $reference
			]);

			throw new RuntimeException('Payment not found');
		}

		/**
		 * ENTITY REHYDRATION (Nextcloud ORM quirk)
		 */
		return $this->paymentMapper->findById($payment->getId());
	}

	/**
	 * Verify that the authenticated user owns the payment identified by the provider reference.
	 *
	 * @throws RuntimeException if the payment does not exist or the user is not the owner.
	 */
	public function assertPaymentOwnership(string $reference, string $userId): Payment
	{
		$payment = $this->fetchPaymentByProviderReference($reference);

		$paymentUserId = $payment->getUserId();
		if ($paymentUserId !== null && $paymentUserId !== $userId) {
			throw new RuntimeException('Access Denied');
		}

		return $payment;
	}

	private function fetchByTransactionId(int $signRequestId): Payment
	{
		$payment = $this->paymentMapper->findLatestPendingByTransactionId($signRequestId);

		if (!$payment) {
			$this->logger->error('[PaymentService] Payment not found', [
				'sign_request_id' => $signRequestId
			]);

			throw new RuntimeException('Payment not found');
		}
		/**
		 * ENTITY REHYDRATION (Nextcloud ORM quirk)
		 */
		return $this->paymentMapper->findById($payment->getId());
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

			provider: $payment->getPaymentProvider(),

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

	private function expirePayment(Payment $payment): void
	{
		$payment->setPaymentStatus(PaymentStatus::FAILED);

		$meta = $payment->getProviderMetadataObject();

		$meta = $meta->with(
			updatedAt: $this->nowImmutable(),
			providerError: [
				'type' => 'expired',
				'timestamp' => $this->now(),
			]
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentMapper->update($payment);
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
	 * Determine whether a pending payment session
	 * is no longer operationally valid.
	 *
	 * IMPORTANT:
	 * - Expiry is a UX/runtime boundary,
	 *   NOT provider settlement truth
	 *
	 * - Expired payments may still later reconcile
	 *   via async provider callbacks
	 *
	 * - Expiry prevents:
	 *   - stale session hydration
	 *   - duplicate polling recovery
	 *   - indefinite pending reuse
	 *   - abandoned payment resurrection
	 *
	 * FE recovery only resumes payments that:
	 * - are still pending
	 * - are not expired
	 */
	private function hasPaymentExpired(Payment $payment): bool
	{
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return false;
		}

		// DateTime
		$createdAt = $payment->getCreatedAtImmutable();

		if ($createdAt === null) {
			return true;
		}

		$now = $this->nowImmutable();

		$diffInSeconds = $now->getTimestamp() - $createdAt->getTimestamp();

		return $diffInSeconds > (self::PAYMENT_EXPIRY_SECONDS); // currently 15 minutes
	}

	/**
	 * @throws \Exception
	 */
	private function now(): string
	{
		return (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		))->format(DATE_ATOM);
	}


	private function nowImmutable(): \DateTimeImmutable
	{
		return new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		);
	}

	private function asDateTime(
		\DateTimeInterface|string|null $value
	): ?\DateTimeImmutable {

		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($value);
		}

		try {
			return new \DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}
	}

	private function appendProviderError(
		PaymentMetadataDTO $meta,
		array $error
	): PaymentMetadataDTO {

		return $meta->with(
			updatedAt: $this->nowImmutable(),
			providerError: [
				...($meta->providerError ?? []),
				...$error,
			]
		);
	}

	private function getProviderPayload(
		PaymentMetadataDTO $meta
	): ProviderPayloadDTO {

		return $meta->providerPayload
			?? new ProviderPayloadDTO();
	}
}
