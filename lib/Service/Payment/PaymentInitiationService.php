<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Service\Payment\DTO\AutoChargeDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\SuggestedMnoDTO;
use OCA\Libresign\Service\Product\ProductService;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Orchestrates a new payment attempt.
 *
 * Handles routing, FX, idempotency, provider execution and the
 * initial persistence that makes async callbacks safe.
 */
class PaymentInitiationService {
	public function __construct(
		private PaymentRepository $paymentRepository,
		private PaymentFactory $paymentFactory,
		private PaymentResponseFactory $responseFactory,
		private PaymentLifecycleService $lifecycleService,
		private MobileMoneyService $mobileMoneyService,
		private CardService $cardService,
		private PaymentRoutingService $paymentRoutingService,
		private FxEngineService $fxEngineService,
		private ProviderAmountNormaliser $providerAmountNormaliser,
		private ProductService $productService,
		private PaymentDateTimeHelper $dateTimeHelper,
		private LoggerInterface $logger,
	) {
	}

	/** Attach the requested pointer to a pending payment that has none. */
	private const WORKFLOW_REUSE_ATTACH = 'attach';
	/** Reuse the pending payment as-is (same draft). */
	private const WORKFLOW_REUSE_SAME = 'reuse';
	/** Expire the pending payment and recreate (points at a different draft). */
	private const WORKFLOW_REUSE_RECREATE = 'recreate';
	/** Strip a stale pointer from the pending payment and reuse it. */
	private const WORKFLOW_REUSE_CLEAR = 'clear';

	/**
	 * Decide how a reusable pending card credit purchase relates to the
	 * requested sponsorship-workflow draft pointer. Pure -- depends only on the
	 * two UUIDs, so it is unit-testable in isolation.
	 *
	 * - requested present, pending absent  -> attach the requested one and reuse
	 * - requested present, equals pending  -> reuse as-is
	 * - requested present, differs pending -> recreate (never overwrite)
	 * - requested absent, pending present  -> clear the stale pointer and reuse
	 * - requested absent, pending absent   -> reuse as-is (no metadata write)
	 *
	 * @return self::WORKFLOW_REUSE_*
	 */
	private static function resolveSponsorshipWorkflowReuseAction(
		?string $pendingUuid,
		?string $requestedUuid,
	): string {
		$hasPending = $pendingUuid !== null && $pendingUuid !== '';
		$hasRequested = $requestedUuid !== null && $requestedUuid !== '';

		if (!$hasRequested) {
			// The new purchase carries no pointer. Strip any stale pointer the
			// pending still holds; otherwise reuse untouched (no metadata write).
			return $hasPending
				? self::WORKFLOW_REUSE_CLEAR
				: self::WORKFLOW_REUSE_SAME;
		}

		if (!$hasPending) {
			return self::WORKFLOW_REUSE_ATTACH;
		}

		return $pendingUuid === $requestedUuid
			? self::WORKFLOW_REUSE_SAME
			: self::WORKFLOW_REUSE_RECREATE;
	}

	/**
	 * Start a new payment attempt.
	 *
	 * @throws \Throwable
	 */
	public function startPayment(StartPaymentDTO $dto): StartPaymentResultDTO|ExistingPaymentResultDTO {
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
		$quantity = $dto->quantity;
		$returnUrl = $dto->returnUrl;
		$sponsorshipWorkflowFileUuid = $dto->sponsorshipWorkflowFileUuid;

		$e164 = null;
		$route = null;
		$ctxMetadata = [];
		$countryCtx = null;
		$fxEngineResult = null;
		$pending = null;
		$providerOverride = null;
		$autoCharge = new AutoChargeDTO(false);

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

		if ($capability === PaymentCapability::MOBILE_MONEY) {
			if (!$phoneNumber) {
				throw new RuntimeException('Phone number is required');
			}

			$routingResult = $this->paymentRoutingService->resolveMobileMoney(
				$phoneNumber,
				false,
				$dto->provider,
			);

			$e164 = $routingResult->identity->e164;
			$route = $routingResult->route;
			$providerOverride = $routingResult->providerOverride;
			$autoCharge = $routingResult->autoCharge;

			$region = $routingResult->identity->region;
			$countryCtx = $routingResult->countryContext;

			$ctxMetadata = [
				'confidenceBreakdown' => $routingResult->identity->confidence,
				'carrier' => $routingResult->identity->carrierHint,
				'region' => $region,
				'verified' => $routingResult->identity->verified,
			];

			$this->logger->info('Mobile money routing result', [
				'country' => $routingResult->countryContext->country,
				'region' => $region,
				'carrier' => $routingResult->identity->carrierHint,
				'confidenceBreakdown' => $routingResult->identity->confidence,
			]);
		}

		if ($capability === PaymentCapability::CARD) {
			if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
				throw new RuntimeException('Valid redirect URL required');
			}

			if (!$returnUrl || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
				throw new RuntimeException('Valid return URL required');
			}

			$route = $this->paymentRoutingService->resolveCard();
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
			'autoCharge' => $autoCharge->toArray(),
		]);

		// The sponsorship-workflow pointer is attached only for a card credit
		// purchase that actually carries a draft UUID. The backend is
		// authoritative here: a UUID supplied for any other purpose/method
		// (e.g. mobile, certificate, sign-request) is ignored.
		$isCardCreditPurchase =
			$methodEnum === PaymentMethod::CARD
			&& $paymentPurpose === PaymentPurpose::CREDIT_PURCHASE;

		$isCardSponsorshipCreditPurchase =
			$isCardCreditPurchase
			&& $sponsorshipWorkflowFileUuid !== null
			&& $sponsorshipWorkflowFileUuid !== '';

		if ($paymentPurpose === PaymentPurpose::SIGN_REQUEST) {
			if ($signRequestId === null) {
				throw new RuntimeException('signRequestId is required');
			}

			if ($signUuid === null || $signUuid === '') {
				throw new RuntimeException('signUuid is required');
			}

			$paidPayment = $this->paymentRepository->findLatestPaidByTransactionId($signRequestId);
			if ($paidPayment) {
				$this->logger->info('[Payment] Returning existing paid payment instead of starting a new one', [
					'sign_request_id' => $signRequestId,
					'payment_id' => $paidPayment->getId(),
				]);
				return $this->responseFactory->buildExistingPaymentResponse($paidPayment);
			}

			$pending = $this->paymentRepository->findLatestPendingByTransactionId($signRequestId);
		} else {
			$pending = $this->paymentRepository->findLatestPendingCreditPurchaseByUserId(
				$userId,
				$productCode
			);
		}

		if ($pending) {
			if (!$this->lifecycleService->hasPaymentExpired($pending)) {
				$meta = $pending->getProviderMetadataObject();

				if (
					$route !== null
					&& $pending->getProviderEnum() !== $route->preferredProvider
				) {
					$this->logger->info('[Payment] Expiring pending payment because route provider changed', [
						'pending_provider' => $pending->getProviderEnum()->value,
						'route_provider' => $route->preferredProvider->value,
						'payment_id' => $pending->getId(),
					]);

					$this->lifecycleService->expirePayment($pending);
				} elseif ($meta->method !== $methodEnum->value) {
					$this->lifecycleService->expirePayment($pending);
				} elseif (
					$methodEnum === PaymentMethod::MOBILE
					&& $e164 !== null
					&& $pending->getPhoneE164Digits() !== null
					&& $pending->getPhoneE164Digits() !== $e164
				) {
					$this->lifecycleService->expirePayment($pending);
				} elseif ($this->lifecycleService->isPendingStaleForRetry($pending)) {
					$resolvedStatus = $this->lifecycleService->queryPayment(
						$pending->getProviderReference()
					);

					if ($resolvedStatus === PaymentStatus::PAID) {
						$payment = $this->paymentRepository->fetchPaymentByProviderReference(
							$pending->getProviderReference()
						);
						return $this->responseFactory->buildExistingPaymentResponse($payment);
					}

					$this->logger->info('[Payment] Expiring stale pending payment for retry after reconciliation', [
						'reference' => $pending->getProviderReference(),
						'payment_id' => $pending->getId(),
						'resolved_status' => $resolvedStatus->value,
						'age_seconds' => $this->dateTimeHelper->nowImmutable()->getTimestamp() - ($pending->getCreatedAtImmutable()?->getTimestamp() ?? 0),
					]);

					$this->lifecycleService->expirePayment($pending);
				} elseif ($isCardCreditPurchase) {
					// Reached only after the pending passed every existing
					// eligibility check above (not expired, provider match, method
					// match, not stale-for-retry). We only reconcile the
					// sponsorship-workflow navigation pointer here; we never
					// override an expire/recreate decision made above.
					$action = self::resolveSponsorshipWorkflowReuseAction(
						$meta->getSponsorshipWorkflowFileUuid(),
						$sponsorshipWorkflowFileUuid,
					);

					if ($action === self::WORKFLOW_REUSE_ATTACH) {
						// Reused pending credit purchase without a sponsorship-workflow
						// pointer yet: attach the current draft's pointer (metadata
						// only, no financial change) and reuse the payment.
						$pending->setProviderMetadataObject(
							$meta->withSponsorshipWorkflowFileUuid($sponsorshipWorkflowFileUuid)
						);
						$this->paymentRepository->update($pending);

						return $this->responseFactory->buildExistingPaymentResponse($pending);
					}

					if ($action === self::WORKFLOW_REUSE_CLEAR) {
						// The new purchase carries no pointer, but the pending still
						// holds a stale one (e.g. an abandoned sponsorship card
						// purchase). Strip it so the user is not navigated to an
						// unrelated draft after payment. Metadata only: no expiry or
						// recreate, and the DPO reference / attempt id / amount /
						// status are untouched.
						$this->logger->info('[Payment] Clearing stale sponsorship pointer from reused credit purchase', [
							'payment_id' => $pending->getId(),
							'stale_file_uuid' => $meta->getSponsorshipWorkflowFileUuid(),
						]);

						$pending->setProviderMetadataObject(
							$meta->withoutSponsorshipWorkflowFileUuid()
						);
						$this->paymentRepository->update($pending);

						return $this->responseFactory->buildExistingPaymentResponse($pending);
					}

					if ($action === self::WORKFLOW_REUSE_SAME) {
						// Same draft, or neither side carries a pointer -- reuse
						// as-is (no metadata write).
						return $this->responseFactory->buildExistingPaymentResponse($pending);
					}

					// WORKFLOW_REUSE_RECREATE: the pending payment points at a
					// DIFFERENT draft. Never silently overwrite its association or
					// route the user to the wrong draft: expire it (consistent with
					// the provider/method mismatch handling above) and fall through
					// to create a fresh payment carrying the correct pointer.
					$this->logger->info('[Payment] Pending credit purchase points at a different sponsorship draft; expiring and recreating', [
						'payment_id' => $pending->getId(),
						'pending_file_uuid' => $meta->getSponsorshipWorkflowFileUuid(),
						'requested_file_uuid' => $sponsorshipWorkflowFileUuid,
					]);

					$this->lifecycleService->expirePayment($pending);
				} else {
					return $this->responseFactory->buildExistingPaymentResponse($pending);
				}
			}

			$this->lifecycleService->expirePayment($pending);

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

		$existing = $this->paymentRepository->findByAttemptId($paymentAttemptId);

		if ($existing) {
			return $this->responseFactory->buildExistingPaymentResponse($existing);
		}

		$product = $this->productService->getDefaultByCode($productCode);

		$totalUses = $product->getUses() * $quantity;
		$unitAmount = $product->getAmount();
		$totalAmount = $unitAmount * $quantity;

		$amountMinor = $totalAmount;
		$currency = $product->getCurrency();
		$uses = $totalUses;

		if ($uses <= 0) {
			throw new RuntimeException('Invalid product configuration');
		}

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
			$fxEngineResult = $this->fxEngineService->identityResult($amountMinor);
		}

		$payment = $this->paymentFactory->createPayment(
			dto: $dto,
			route: $route,
			fxEngineResult: $fxEngineResult,
			amountMinor: $amountMinor,
			unitAmount: $unitAmount,
			uses: $uses,
			currency: $currency,
			e164: $e164,
			paymentAttemptId: $paymentAttemptId,
		);

		$normalisedAmount = $this->providerAmountNormaliser->normalise(
			$fxEngineResult->displayAmount,
			$fxEngineResult->displayCurrency,
			$route->preferredProvider
		);

		try {
			$res = match ($route->capability) {
				PaymentCapability::MOBILE_MONEY
				=> $this->mobileMoneyService->initiate(
					$route,
					new MobileMoneyPayloadDTO(
						phone: $e164,
						amount: (float)$normalisedAmount,
						currency: $fxEngineResult->displayCurrency,
						signUuid: $signUuid,
						signRequestId: $signRequestId,
						userId: $userId,
						email: $userEmail,
						callbackUrl: $callbackUrl,
						redirectUrl: $redirectUrl,
					)
				),

				PaymentCapability::CARD
				=> $this->cardService->initiateCard(
					new CardPaymentPayloadDTO(
						amount: (float)$normalisedAmount,
						currency: $fxEngineResult->displayCurrency,
						userId: $userId,
						email: $userEmail,
						redirectUrl: $redirectUrl,
						callbackUrl: $callbackUrl,
						returnUrl: $returnUrl,
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
				updatedAt: $this->dateTimeHelper->nowImmutable(),
				providerError: [
					'message' => $e->getMessage(),
					'provider' => $route?->preferredProvider?->value ?? 'unknown',
					'timestamp' => time(),
				]
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentRepository->update($payment);

			throw $e;
		}

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
				updatedAt: $this->dateTimeHelper->nowImmutable(),
				providerError: [
					'message' => $message,
					'provider' => $route?->preferredProvider?->value ?? 'unknown',
					'timestamp' => time(),
				]
			);
			$payment->setProviderMetadataObject($meta);
			$this->paymentRepository->update($payment);

			throw new RuntimeException($message);
		}

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
			updatedAt: $this->dateTimeHelper->nowImmutable(),
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
			autoCharge: $autoCharge,
			instructions: $metaPayload['instructions'] ?? null,
			context: $ctxMetadata,
			providerExecutionState: $res->providerExecutionState,
			providerPayload: ProviderPayloadDTO::fromArray(
				$metaPayload['providerPayload'] ?? []
			),
			providerError: null,
			// Originating application page the customer started from.
			returnUrl: $returnUrl,
		);

		if ($isCardSponsorshipCreditPurchase) {
			$metadata = $metadata->withSponsorshipWorkflowFileUuid($sponsorshipWorkflowFileUuid);
		}

		$payment->setProviderMetadataObject($metadata);
		$this->paymentRepository->update($payment);

		return $this->responseFactory->buildStartPaymentResult(
			payment: $payment,
			method: $methodEnum,
		);
	}
}
