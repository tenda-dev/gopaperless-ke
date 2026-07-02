<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Enum\ResolutionConfidence;
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
		private PhoneResolutionService $phoneResolutionService,
		private MnoRoutingRegistry $mnoRoutingRegistry,
		private MnoDetectionRegistry $mnoDetectionRegistry,
		private PaymentCountryResolver $countryResolver,
		private FxEngineService $fxEngineService,
		private ProviderAmountNormaliser $providerAmountNormaliser,
		private ProductService $productService,
		private PaymentDateTimeHelper $dateTimeHelper,
		private LoggerInterface $logger,
	) {
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

		$e164 = null;
		$route = null;
		$ctxMetadata = [];
		$countryCtx = null;
		$fxEngineResult = null;
		$pending = null;

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

			$e164 = $resolutionDto->e164;

			$countryCtx = $this->countryResolver->resolve($region);

			if (!$countryCtx) {
				throw new RuntimeException('Unsupported country');
			}

			$detection = $this->mnoDetectionRegistry->resolve(
				$region,
				$resolutionDto->national,
			);

			$finalCarrier = $detection['mno'] ?? $resolutionDto->carrierHint;
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
				'carrier' => $finalCarrier,
				'region' => $region,
			];

			$this->logger->info('Mobile money routing result', [
				'country' => $countryCtx->country,
				'region' => $region,
				'carrier' => $finalCarrier,
				'confidenceBreakdown' => $finalConfidence,
			]);
		}

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
			instructions: $metaPayload['instructions'] ?? null,
			context: $ctxMetadata,
			providerExecutionState: $res->providerExecutionState,
			providerPayload: ProviderPayloadDTO::fromArray(
				$metaPayload['providerPayload'] ?? []
			),
			providerError: null,
		);

		$payment->setProviderMetadataObject($metadata);
		$this->paymentRepository->update($payment);

		return $this->responseFactory->buildStartPaymentResult(
			payment: $payment,
			method: $methodEnum,
		);
	}

	private function validatePhoneNumber(string $phone): void {
		$phoneUtil = PhoneNumberUtil::getInstance();

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
		} catch (NumberParseException) {
			throw new \InvalidArgumentException(
				'Invalid phone number. Use international format (e.g., +254...)'
			);
		}
	}
}
