<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentErrorCode;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentPricingDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentRouteContextDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\Exceptions\PaymentInvariantException;
use OCA\Libresign\Service\Payment\Exceptions\PaymentValidationException;
use OCA\Libresign\Service\PhoneNumber\PhoneNumberService;
use OCA\Libresign\Service\Product\ProductService;
use Psr\Log\LoggerInterface;

/**
 * Resolves the two pieces of context a payment needs before it can be
 * created: WHERE it routes (resolveRoute) and WHAT it costs (price).
 *
 * The split is deliberate and ordered: resolveRoute runs first (its result
 * feeds the dedup guards), pricing/FX runs only AFTER the guards clear so we
 * never lock an FX rate or hit the FX engine for an attempt the guards will
 * short-circuit. This service reads registries/resolvers and throws routing
 * preconditions; it never touches the Payment entity, mapper, or DB.
 *
 */
class PaymentContextService
{
	public function __construct(
		private PhoneNumberService $phoneNumberService,
		private PhoneResolutionService $phoneResolutionService,
		private MnoRoutingRegistry $mnoRoutingRegistry,
		private PaymentCountryResolver $countryResolver,
		private MnoDetectionRegistry $mnoDetectionRegistry,
		private ProductService $productService,
		private FxEngineService $fxEngineService,
		private ProviderAmountNormaliser $providerAmountNormaliser,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Resolve capability → phone (mobile) → MNO detection → route, honouring
	 * an advisory frontend provider hint only when the backend route is itself
	 * uncertain. Throws all routing preconditions (missing/invalid phone,
	 * unsupported region, missing redirect URL for card).
	 *
	 * @throws PaymentValidationException on a bad/absent routing precondition
	 * @throws PaymentInvariantException  on an impossible route result
	 */
	public function resolveRoute(StartPaymentDTO $dto): PaymentRouteContextDTO
	{
		$methodEnum = $dto->paymentMethod;

		if ($methodEnum === null) {
			// Unreachable: StartPaymentDTO::paymentMethod is non-nullable.
			// Defensive invariant.
			throw new PaymentValidationException(
				PaymentErrorCode::MISSING_FIELD,
				'Invalid payment method',
				retryable: false,
			);
		}

		$capability = match ($methodEnum) {
			PaymentMethod::MOBILE => PaymentCapability::MOBILE_MONEY,
			PaymentMethod::CARD => PaymentCapability::CARD,
		};

		$e164 = null;
		$countryCtx = null;
		$ctxMetadata = [];
		$route = null;

		/**
		 * MOBILE FLOW RESOLUTION → DETECTION → ROUTING
		 */
		if ($capability === PaymentCapability::MOBILE_MONEY) {
			$phoneNumber = $dto->phoneNumber;

			if (!$phoneNumber) {
				throw new PaymentValidationException(
					PaymentErrorCode::MISSING_FIELD,
					'Phone number is required',
					retryable: false
				);
			}

			$this->validatePhoneNumber($phoneNumber);

			$resolutionDto = $this->phoneResolutionService->resolve($phoneNumber);
			$region = $resolutionDto->region;

			if (!$resolutionDto->valid || !$region) {
				throw new PaymentValidationException(
					PaymentErrorCode::INVALID_PHONE,
					'Unable to resolve phone number',
					retryable: true
				);
			}

			if (!$this->mnoRoutingRegistry->supportsRegion($region)) {
				throw new PaymentValidationException(
					PaymentErrorCode::UNSUPPORTED_REGION,
					sprintf(
						'Unsupported region: %s. Supported regions: %s',
						$region,
						implode(', ', $this->mnoRoutingRegistry->supportedRegions())
					),
					retryable: false,
				);
			}

			// e164 with the + (persisted — remove per provider if needed)
			$e164 = $resolutionDto->e164;

			$countryCtx = $this->countryResolver->resolve($region);

			if (!$countryCtx) {
				throw new PaymentValidationException(
					PaymentErrorCode::UNSUPPORTED_REGION,
					'Unsupported country',
					retryable: false
				);
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
				$finalConfidence,
			);

			if (!$route->capability) {
				// Unreachable: route() always returns a routed capability
				// (ambiguous carriers fall back to a valid DPO mobile-money
				// route with AMBIGUOUS confidence, not a failure).
				// Serves as a defensive invariant.
				throw new PaymentInvariantException('Unable to determine payment route');
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

		/**
		 * CARD FLOW ROUTING
		 */
		if ($capability === PaymentCapability::CARD) {
			$redirectUrl = $dto->redirectUrl;

			if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
				throw new PaymentValidationException(
					PaymentErrorCode::MISSING_FIELD,
					'Valid redirect URL required',
					retryable: false
				);
			}

			$route = $this->mnoRoutingRegistry->route(
				capability: $capability,
				country: null,
				region: null,
				carrier: null,
				confidence: ResolutionConfidence::HIGH,
			);
		}

		$route = $this->applyProviderHint($dto, $capability, $route);

		return new PaymentRouteContextDTO(
			route: $route,
			capability: $capability,
			e164: $e164,
			countryCtx: $countryCtx,
			ctxMetadata: $ctxMetadata,
		);
	}

	/**
	 * Product → total amount → FX → provider-normalised amount.
	 *
	 * Runs AFTER the guards so no FX rate is locked for a short-circuited
	 * attempt. Currency selection preserves the original rule: Daraja → KES
	 * (identity FX), Card → provider-handled (no FX), else country currency.
	 *
	 * @throws PaymentInvariantException on invalid product configuration
	 */
	public function price(
		StartPaymentDTO $dto,
		PaymentRouteContextDTO $ctx
		): PaymentPricingDTO
	{
		$route = $ctx->route;
		$quantity = $dto->quantity ?? 1;

		$product = $this->productService->getDefaultByCode($dto->productCode);

		$unitAmount = $product->getAmount();
		$amountMinor = $unitAmount * $quantity;
		$currency = $product->getCurrency();
		$uses = $product->getUses() * $quantity;

		if ($uses <= 0) {
			throw new PaymentInvariantException('Invalid product configuration');
		}

		$targetCurrency = match (true) {
			$route->preferredProvider === PaymentProvider::DARAJA => 'KES',
			$route->capability === PaymentCapability::CARD => null,
			default => $ctx->countryCtx->currency,
		};

		/**
		 * IMPORTANT: FX CONVERSION MUST HAPPEN BEFORE PAYMENT CREATION AND PROVIDER CALL
		 * Currency used for pricing this payment.
		 *
		 * - DPO mobile → local currency (FX applied)
		 * - Daraja → KES (identity FX)
		 * - Card → handled by provider (bypass FX)
		 */
		if ($targetCurrency !== null) {
			$fx = $this->fxEngineService->convert(
				kesAmount: $amountMinor,
				targetCurrency: $targetCurrency,
			);
		} else {
			$fx = $this->fxEngineService->identityResult($amountMinor);
		}

		$normalisedAmount = $this->providerAmountNormaliser->normalise(
			$fx->displayAmount,
			$fx->displayCurrency,
			$route->preferredProvider,
		);

		return new PaymentPricingDTO(
			amountMinor: $amountMinor,
			currency: $currency,
			uses: $uses,
			unitAmount: $unitAmount,
			quantity: $quantity,
			fx: $fx,
			normalisedAmount: $normalisedAmount,
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
	private function applyProviderHint(
		StartPaymentDTO $dto,
		PaymentCapability $capability,
		MnoRoutingResultDTO $route,
	): MnoRoutingResultDTO {
		if ($dto->provider === null || $capability !== PaymentCapability::MOBILE_MONEY) {
			return $route;
		}

		if ($route->requiresUserSelection()) {
			$route = $route->withPreferredProvider($dto->provider);
			$this->logger->info('Using frontend provider hint for uncertain route', [
				'hint' => $dto->provider->value,
				'route_confidence' => $route->confidence->value,
			]);
			return $route;
		}

		$this->logger->info('Ignoring frontend provider hint; backend route is authoritative', [
			'hint' => $dto->provider->value,
			'route_provider' => $route->preferredProvider->value,
			'route_confidence' => $route->confidence->value,
		]);

		return $route;
	}

	private function validatePhoneNumber(string $phone): void
	{
		if (!str_starts_with($phone, '+')) {
			throw new PaymentValidationException(
				PaymentErrorCode::INVALID_PHONE,
				'Phone number must be in international format',
				retryable: true,
			);
		}

		$resolved = $this->phoneNumberService->resolve($phone);

		if (!$resolved->valid) {
			throw new PaymentValidationException(
				PaymentErrorCode::INVALID_PHONE,
				'The provided phone number is not valid.',
				retryable: true,
			);
		}
	}
}
