<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentFlowMode;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;

/**
 *
 * MNO ROUTING REGISTRY
 *
 * Single source of truth for:
 *   - Which capability handles a given carrier  (mobile_money | card)
 *   - Which provider is preferred              (daraja | dpo)
 *   - DPO MNO key                              (exact string DPO expects in ChargeTokenMobile)
 *   - Transaction limits enforced by DPO/telco (min, max, currency, decimals)
 *   - Payment mode                             (stk_push | instructions | both)
 *
 * RESPONSIBILITIES
 *   This class answers: given a confirmed carrier + region,
 *   which service capability handles it, which provider is preferred,
 *   and what constraints apply?
 *
 *   It does NOT:
 *   - detect MNO from a raw phone number   → DpoMnoRegistry
 *   - parse or validate phone numbers      → PhoneResolutionService
 *   - call DPO or Daraja APIs             → Provider adapters
 *
 */
class MnoRoutingRegistry {
	// ROUTING TABLE

	// Keyed by ISO 3166-1 alpha-2 region code.
	// Each entry is a list of carrier → route mappings.

	// Fields per route:

	//    match          string[]   Lowercase substrings matched against
	//                              the normalised carrier name from
	//                              PhoneResolutionService.
	//                              First match wins (order matters).

	//    mnoKey         string     Exact MNO string DPO expects in
	//                              ChargeTokenMobile <MNO> field.
	//                              Verify against GetMobilePaymentOptions.

	//    capability     string     PaymentCapability

	//    preferredProvider string  PaymentProvider

	//    mode           string     PaymentFlowMode

	//    currency       string     ISO 4217 currency code

	//    minAmount      float      Minimum transaction amount (in currency units)

	//    maxAmount      float      Maximum transaction amount (in currency units)
	//                              NOTE: telco tier limits may be lower — this is
	//                              the DPO-documented ceiling only.

	//    supportsDecimals bool     Whether the currency/MNO pair accepts decimal amounts

	//    notes          string     Caveats, portability flags, or operational notes.


	private const ROUTES = [

		// Kenya (KE) — KES — +254
		//
		// Two DPO-supported MNOs: Mpesa and Airtel.
		// Safaricom/Mpesa is routed via Daraja (STK push native).
		// Airtel Kenya goes through DPO.
		// No decimal support in KES.
		// Source: DPO MNO Advisory Feb 2025 + Daraja integration.
		'KE' => [
			[
				'match' => ['safaricom', 'mpesa', 'm-pesa'],
				'mnoKey' => 'Mpesa',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DARAJA,
				'mode' => PaymentFlowMode::STK_PUSH,
				'currency' => 'KES',
				'minAmount' => 1,
				'maxAmount' => 250000,
				'supportsDecimals' => false,
				'notes' => 'Safaricom/M-Pesa is routed natively through Daraja STK push',
			],
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'KES',
				'minAmount' => 10,
				'maxAmount' => 250000,
				'supportsDecimals' => false,
			],
		],

		// Tanzania (TZ) — TZS — +255
		//
		// Six DPO-supported MNOs. All share the same TZS limits.
		// Min 200 TZS required for STK push.
		// Zantel and TTCL are flagged ambiguous in DpoMnoRegistry
		// due to prefix overlap — confidence passed in from upstream.
		// Source: DPO Advisory Feb 2025.
		//
		'TZ' => [
			[
				'match' => ['vodacom'],
				'mnoKey' => 'Vodacom',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['tigo', 'mipawa', 'mi-pawa'],
				'mnoKey' => 'Tigo',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['halotel', 'viettel'],
				'mnoKey' => 'Halotel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['zantel'],
				'mnoKey' => 'Zantel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['ttcl'],
				'mnoKey' => 'TTCL',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
		],


		// Zanzibar (ZNZ) — TZS — treated as separate DPO region
		//
		// DPO advisory lists "Zanzibar" separately with MNO "Zntigo".
		// Uses TZS and shares the same limits as Tanzania.
		// ISO 3166-1 does not have a code for Zanzibar — DPO uses
		// the country name. Callers should normalise as needed.

		'ZNZ' => [
			[
				'match' => ['zntigo', 'zanzibar', 'tigo'],
				'mnoKey' => 'Zntigo',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'TZS',
				'minAmount' => 200,
				'maxAmount' => 3000000,
				'supportsDecimals' => false,
			],
		],

		// Uganda (UG) — UGX — +256
		//
		// Two DPO-supported MNOs: MTN and Airtel.
		// High max limit (5M UGX) but depends on telco tier level.
		// Source: DPO Advisory Feb 2025.

		'UG' => [
			[
				'match' => ['mtn'],
				'mnoKey' => 'MTN',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'UGX',
				'minAmount' => 500,
				'maxAmount' => 5000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'UGX',
				'minAmount' => 500,
				'maxAmount' => 5000000,
				'supportsDecimals' => false,
			],
		],


		// Rwanda (RW) — RWF — +250
		//
		// Two DPO-supported MNOs: MTN and Airtel.
		// Prefix overlap on 072 flagged in DpoMnoRegistry.
		// DPO advisory has a typo: "2,0000,000" — treating as 2,000,000.
		// Source: DPO Advisory Feb 2025.
		'RW' => [
			[
				'match' => ['mtn'],
				'mnoKey' => 'MTN',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'RWF',
				'minAmount' => 10,
				'maxAmount' => 2000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'RWF',
				'minAmount' => 10,
				'maxAmount' => 2000000,
				'supportsDecimals' => false,
			],
		],

		// Ghana (GH) — GHS — +233
		//
		// Two DPO-supported MNOs: Vodacom and MTN.
		// Note: DPO advisory lists "Vodacom" for Ghana — this is likely
		// Vodafone Ghana (not Vodacom Tanzania). DPO key is "Vodacom".
		// GHS supports decimals.
		// Source: DPO Advisory Feb 2025.
		'GH' => [
			[
				'match' => ['mtn'],
				'mnoKey' => 'MTN',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'GHS',
				'minAmount' => 0.1,
				'maxAmount' => 15000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['vodacom', 'vodafone'],
				'mnoKey' => 'Vodacom',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'GHS',
				'minAmount' => 0.1,
				'maxAmount' => 10000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['airtel', 'tigo', 'airteltigo'],
				'mnoKey' => null, // Not in DPO advisory
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::INSTRUCTIONS,
				'currency' => 'GHS',
				'minAmount' => 0.1,
				'maxAmount' => 10000,
				'supportsDecimals' => true,
			],
		],

		// Ivory Coast / Côte d'Ivoire (CI) — XOF — +225
		//
		// Two DPO-supported MNOs: MTN and Orange.
		// XOF (West African CFA franc) does not support decimals.
		// Source: DPO Advisory Feb 2025.
		'CI' => [
			[
				'match' => ['mtn'],
				'mnoKey' => 'MTN',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'XOF',
				'minAmount' => 100,
				'maxAmount' => 2000000,
				'supportsDecimals' => false,
			],
			[
				'match' => ['orange'],
				'mnoKey' => 'Orange',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'XOF',
				'minAmount' => 100,
				'maxAmount' => 2000000,
				'supportsDecimals' => false,
			],
		],

		// Malawi (MW) — MWK — +265
		//
		// One DPO-supported MNO: Airtel.
		// TNM is present in the market but NOT listed in DPO advisory.
		// MWK supports decimals per DPO advisory.
		// Source: DPO Advisory Feb 2025 + DpoMnoRegistry.
		'MW' => [
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'MWK',
				'minAmount' => 50,
				'maxAmount' => 350000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['tnm', 'telekom networks'],
				'mnoKey' => null, // Not in DPO advisory
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::INSTRUCTIONS,
				'currency' => 'MWK',
				'minAmount' => 50,
				'maxAmount' => 350000,
				'supportsDecimals' => true,
			],
		],

		// Zambia (ZM) — ZMW — +260
		//
		// Two DPO-supported MNOs: MTN and Airtel.
		// Zamtel is in the market but NOT listed in DPO advisory.
		// ZMW supports decimals.
		// Source: DPO Advisory Feb 2025 + DpoMnoRegistry.
		'ZM' => [
			[
				'match' => ['mtn'],
				'mnoKey' => 'MTN',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'ZMW',
				'minAmount' => 0.1,
				'maxAmount' => 20000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['airtel'],
				'mnoKey' => 'Airtel',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'ZMW',
				'minAmount' => 1,
				'maxAmount' => 10000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['zamtel'],
				'mnoKey' => null, // Not in DPO advisory
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::INSTRUCTIONS,
				'currency' => 'ZMW',
				'minAmount' => 1,
				'maxAmount' => 10000,
				'supportsDecimals' => true,
			],
		],

		// Zimbabwe (ZW) — ZWL / USD — +263
		//
		// One DPO-supported MNO: EcoCash (Econet).
		// Dual currency: ZWL and USD both supported by EcoCash.
		// OneWallet and Telecash not in DPO advisory.
		// Source: DPO Advisory Feb 2025 + DpoMnoRegistry.
		'ZW' => [
			[
				'match' => ['ecocash', 'econet'],
				'mnoKey' => 'EcoCash',
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::BOTH,
				'currency' => 'ZWL', // primary; USD also supported
				'altCurrency' => 'USD',
				'minAmount' => 0.1,
				'maxAmount' => 1000000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['onemoney', 'one money', 'netone'],
				'mnoKey' => null, // Not in DPO advisory
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::INSTRUCTIONS,
				'currency' => 'ZWL',
				'minAmount' => 0.1,
				'maxAmount' => 1000000,
				'supportsDecimals' => true,
			],
			[
				'match' => ['telecash', 'telecel'],
				'mnoKey' => null, // Not in DPO advisory
				'capability' => PaymentCapability::MOBILE_MONEY,
				'preferredProvider' => PaymentProvider::DPO,
				'mode' => PaymentFlowMode::INSTRUCTIONS,
				'currency' => 'ZWL',
				'minAmount' => 0.1,
				'maxAmount' => 1000000,
				'supportsDecimals' => true,
			],
		],

	];


	/**
	 * Route a carrier to a capability + provider decision.
	 *
	 * @param string|null $region ISO 3166-1 alpha-2 (e.g. 'KE', 'TZ')
	 *                            or 'ZNZ' for Zanzibar.
	 * @param string|null $carrier Carrier name from PhoneResolutionService or MnoDetectionRegistry
	 *                             mno key. Will be normalised to lowercase internally.
	 * @param ResolutionConfidence $confidence 'high' | 'ambiguous' | 'unknown'
	 *                                         Passed in from MnoDetectionRegistry or PhoneResolutionService.
	 *                                         This class NEVER overrides a lower confidence —
	 *                                         it may only degrade it (e.g. null mnoKey).
	 *
	 * @return MnoRoutingResultDTO
	 */
	public function route(
		PaymentCapability $capability,
		?string $country,
		?string $region,
		?string $carrier,
		ResolutionConfidence $confidence = ResolutionConfidence::UNKNOWN,
	): MnoRoutingResultDTO {

		if ($capability === PaymentCapability::CARD) {

			return new MnoRoutingResultDTO(
				capability: PaymentCapability::CARD,
				preferredProvider: PaymentProvider::DPO,
				mnoKey: null,
				mode: PaymentFlowMode::INSTRUCTIONS,
				currency: null,
				altCurrency: null,
				minAmount: null,
				maxAmount: null,
				supportsDecimals: true,
				confidence: ResolutionConfidence::HIGH,
				notes: 'Card flow bypasses MNO routing',
				country: $country,
				region: $region,
			);
		}

		$region = $region  ? strtoupper(trim($region))  : null;
		$carrier = $carrier ? strtolower(trim($carrier)) : null;

		// No region
		if ($region === null) {
			return $this->build(
				PaymentCapability::MOBILE_MONEY,
				PaymentProvider::DPO,
				null,
				PaymentFlowMode::INSTRUCTIONS,
				null, null, null, null, null,
				ResolutionConfidence::UNKNOWN,
				'No region provided',
				$country,
				$region,
			);
		}

		// Unsupported region
		if (!isset(self::ROUTES[$region])) {
			return $this->build(
				PaymentCapability::MOBILE_MONEY,
				PaymentProvider::DPO,
				null,
				PaymentFlowMode::INSTRUCTIONS,
				null, null, null, null, null,
				ResolutionConfidence::UNKNOWN,
				"Unsupported region {$region}",
				$country,
				$region,
			);
		}

		// No carrier
		if ($carrier === null) {
			return $this->build(
				PaymentCapability::MOBILE_MONEY,
				PaymentProvider::DPO,
				null,
				PaymentFlowMode::INSTRUCTIONS,
				null, null, null, null, null,
				ResolutionConfidence::AMBIGUOUS,
				'Carrier missing',
				$country,
				$region,
			);
		}

		// Match carrier
		foreach (self::ROUTES[$region] as $route) {
			foreach ($route['match'] as $fragment) {
				if (str_contains($carrier, $fragment)) {

					$resolvedConfidence = $route['mnoKey'] === null
						? ResolutionConfidence::AMBIGUOUS
						: $confidence;

					return $this->build(
						$route['capability'],
						$route['preferredProvider'],
						$route['mnoKey'],
						$route['mode'],
						$route['currency'] ?? null,
						$route['altCurrency'] ?? null,
						$route['minAmount'] ?? null,
						$route['maxAmount'] ?? null,
						$route['supportsDecimals'] ?? null,
						$resolvedConfidence,
						$route['notes'] ?? null,
						$country,
						$region,
					);
				}
			}
		}

		// No match
		return $this->build(
			PaymentCapability::MOBILE_MONEY,
			PaymentProvider::DPO,
			null,
			PaymentFlowMode::INSTRUCTIONS,
			null, null, null, null, null,
			ResolutionConfidence::AMBIGUOUS,
			"Carrier {$carrier} not matched in {$region}",
			$country,
			$region,
		);
	}

	public function supportsRegion(string $region): bool {
		return isset(self::ROUTES[strtoupper($region)]);
	}

	public function supportedRegions(): array {
		return array_keys(self::ROUTES);
	}

	public function supportsMno(string $mno): bool {
		$mno = strtolower(trim($mno));

		if ($mno === '') {
			return false;
		}

		foreach (self::ROUTES as $routes) {
			foreach ($routes as $route) {
				if (
					$route['mnoKey'] !== null
					&& strtolower($route['mnoKey']) === $mno
				) {
					return true;
				}
			}
		}

		return false;
	}


	public function supportedMnosByRegion(): array {
		$regions = [];

		foreach (self::ROUTES as $region => $routes) {
			$mnos = [];

			foreach ($routes as $route) {
				if ($route['mnoKey'] === null) {
					continue;
				}

				$mno = strtolower($route['mnoKey']);

				if (!isset($mnos[$mno])) {
					$mnos[$mno] = [
						'value' => $mno,
						'label' => $route['mnoKey'],
					];
				}
			}

			if ($mnos !== []) {
				$regions[$region] = array_values($mnos);
			}
		}

		return $regions;
	}

	/**
	 * Whether an (MNO, provider) combination is a routable payment path.
	 *
	 * True when the provider is the MNO's default rail, or when the provider
	 * is DPO and the MNO has a DPO MNO key. Everything else (e.g. Daraja for a
	 * non-Safaricom MNO) is unsupported. Used to reject invalid admin override
	 * combinations at save time.
	 */
	public function supportsRoute(?string $region, string $mno, PaymentProvider $provider): bool {
		$matched = $this->matchRoute($region, $mno);

		if ($matched === null) {
			return false;
		}

		if ($matched['preferredProvider'] === $provider) {
			return true;
		}

		if ($provider === PaymentProvider::DPO && ($matched['mnoKey'] ?? null) !== null) {
			return true;
		}

		return false;
	}

	/**
	 * Like route(), but for an explicit provider (an admin override).
	 *
	 * Returns a COHERENT route for that provider — provider, mode, mnoKey,
	 * currency and limits are consistent — instead of mutating preferredProvider
	 * on a route assembled for a different provider. Throws when the (MNO,
	 * provider) combination is not supported.
	 *
	 * @throws \InvalidArgumentException on an unsupported combination
	 */
	public function routeForProvider(
		PaymentCapability $capability,
		?string $country,
		?string $region,
		?string $carrier,
		PaymentProvider $provider,
		ResolutionConfidence $confidence = ResolutionConfidence::UNKNOWN,
	): MnoRoutingResultDTO {
		$base = $this->route($capability, $country, $region, $carrier, $confidence);

		// Provider already matches the derived route -> already coherent.
		if ($base->preferredProvider === $provider) {
			return $base;
		}

		// DPO can serve any MNO that has a DPO MNO key; rebuild a coherent DPO route.
		if ($provider === PaymentProvider::DPO && $base->mnoKey !== null) {
			return $this->build(
				$base->capability,
				PaymentProvider::DPO,
				$base->mnoKey,
				PaymentFlowMode::BOTH,
				$base->currency,
				$base->altCurrency,
				$base->minAmount,
				$base->maxAmount,
				$base->supportsDecimals,
				$base->confidence,
				'Provider override: DPO route for ' . ($carrier ?? 'unknown'),
				$base->country,
				$base->region,
			);
		}

		throw new \InvalidArgumentException(sprintf(
			'Provider %s is not supported for MNO %s in region %s',
			$provider->value,
			$carrier ?? 'unknown',
			$region ?? 'unknown',
		));
	}


	/**
	 * Build a coherent route from an explicit canonical MNO and provider.
	 *
	 * Unlike routeForProvider(), this uses the canonical MNO directly instead
	 * of deriving it from a carrier hint.
	 *
	 * @throws \InvalidArgumentException on an unsupported region or route.
	 */
	public function routeForMno(
		PaymentCapability $capability,
		?string $country,
		?string $region,
		string $mno,
		PaymentProvider $provider,
		ResolutionConfidence $confidence = ResolutionConfidence::HIGH,
	): MnoRoutingResultDTO {
		if ($capability === PaymentCapability::CARD) {
			return $this->route(
				$capability,
				$country,
				$region,
				null,
				$confidence,
			);
		}

		$region = $region ? strtoupper(trim($region)) : null;
		$mno = strtolower(trim($mno));

		if ($region === null || !isset(self::ROUTES[$region])) {
			throw new \InvalidArgumentException(sprintf(
				'Unsupported region %s',
				$region ?? 'unknown',
			));
		}

		foreach (self::ROUTES[$region] as $route) {
			if (
				$route['mnoKey'] !== null
				&& strtolower($route['mnoKey']) === $mno
			) {
				if (
					$route['preferredProvider'] === $provider
					|| (
						$provider === PaymentProvider::DPO
						&& $route['mnoKey'] !== null
					)
				) {
					return $this->build(
						$route['capability'],
						$provider,
						$route['mnoKey'],
						$provider === PaymentProvider::DPO
							? PaymentFlowMode::BOTH
							: $route['mode'],
						$route['currency'] ?? null,
						$route['altCurrency'] ?? null,
						$route['minAmount'] ?? null,
						$route['maxAmount'] ?? null,
						$route['supportsDecimals'] ?? null,
						$confidence,
						'Explicit MNO/provider route',
						$country,
						$region,
					);
				}

				break;
			}
		}

		throw new \InvalidArgumentException(sprintf(
			'Provider %s is not supported for MNO %s in region %s',
			$provider->value,
			$mno,
			$region,
		));
	}

	/**
	 * Resolve the canonical MNO key for a provider route.
	 *
	 * Uses the same carrier matching as route() and returns the
	 * canonical mnoKey for the matched provider.
	 */
	public function mnoKeyForProvider(
		?string $region,
		?string $carrier,
		PaymentProvider $provider,
	): ?string {
		$route = $this->matchRoute($region, $carrier);

		if ($route === null || $route['mnoKey'] === null) {
			return null;
		}

		if ($route['preferredProvider'] !== $provider) {
			return null;
		}

		return $route['mnoKey'];
	}

	/**
	 * Return the raw ROUTES entry matched for a region + carrier, or null.
	 * Mirrors route()'s matching so callers can tell a real match from the
	 * DPO fallback.
	 *
	 * @return array<string, mixed>|null
	 */
	private function matchRoute(?string $region, ?string $carrier): ?array {
		$region = $region ? strtoupper(trim($region)) : null;
		$carrier = $carrier ? strtolower(trim($carrier)) : null;

		if ($region === null || $carrier === null || !isset(self::ROUTES[$region])) {
			return null;
		}

		foreach (self::ROUTES[$region] as $route) {
			foreach ($route['match'] as $fragment) {
				if (str_contains($carrier, $fragment)) {
					return $route;
				}
			}
		}

		return null;
	}

	/**
	 * Validate amount using DTO
	 */
	public function validateAmount(MnoRoutingResultDTO $route, float $amount): array {
		try {
			$route->validateAmount($amount);
			return ['valid' => true, 'reason' => null];
		} catch (\InvalidArgumentException $e) {
			return ['valid' => false, 'reason' => $e->getMessage()];
		}
	}

	/**
	 * Build DTO
	 */
	private function build(
		PaymentCapability $capability,
		PaymentProvider $provider,
		?string $mno,
		PaymentFlowMode $mode,
		?string $currency,
		?string $altCurrency,
		?float $min,
		?float $max,
		?bool $decimals,
		ResolutionConfidence $confidence,
		?string $notes,
		?string $country,
		?string $region,
	): MnoRoutingResultDTO {
		return new MnoRoutingResultDTO(
			$capability,
			$provider,
			$mno,
			$mode,
			$currency,
			$altCurrency,
			$min,
			$max,
			$decimals,
			$confidence,
			$notes,
			$country,
			$region,
		);
	}
}
