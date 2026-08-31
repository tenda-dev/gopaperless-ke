<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\PhoneMnoCache;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\PaymentPhoneResolutionDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoIdentityDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoResolutionDTO;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves a phone number to an MNO identity and optional authoritative
 * provider override.
 *
 * Resolution precedence when v2 is enabled:
 *
 * active override > verified cache > valid cache > detection > fallback
 *
 * When v2 is disabled, the resolver falls back to detection only and never
 * reads or writes the override/cache tables.
 */
class PhoneMnoResolver {
	/**
	 * Bump when MnoDetectionRegistry, the phone metadata/library, or the
	 * resolution logic changes. Cached rows stamped with a different value
	 * are treated as a miss and re-resolved.
	 */
	public const RESOLVER_VERSION = '1';

	public function __construct(
		private PhoneMnoCacheService $cacheService,
		private PhoneMnoOverrideResolver $overrideResolver,
		private PhoneResolutionService $phoneResolutionService,
		private MnoDetectionRegistry $mnoDetectionRegistry,
		private PaymentCountryResolver $countryResolver,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Deterministic, library-free normalization used as the override/cache key.
	 */
	public static function normalizePhoneKey(?string $raw): ?string {
		if ($raw === null) {
			return null;
		}

		$digits = preg_replace('/\D/', '', trim($raw));

		if ($digits === null || $digits === '') {
			return null;
		}

		return '+' . $digits;
	}

	public function resolve(string $rawPhone): PhoneMnoResolutionDTO {
		$key = self::normalizePhoneKey($rawPhone);

		if ($key === null) {
			return new PhoneMnoResolutionDTO(
				identity: PhoneMnoIdentityDTO::invalid(),
			);
		}

		if (!$this->isV2Enabled()) {
			return $this->resolveLegacy($rawPhone);
		}

		$override = $this->overrideResolver->findOverride($key);
		if ($override !== null) {
			$identity = $this->overrideResolver->buildOverrideIdentity($rawPhone, $key, $override);

			if (!$identity->valid) {
				return new PhoneMnoResolutionDTO(identity: $identity);
			}

			$provider = $this->overrideResolver->providerFromOverride($override);

			return new PhoneMnoResolutionDTO(
				identity: $identity->withVerified(
					$this->overrideResolver->isVerifiedOverrideMatch(
						$key,
						$override,
						$provider,
						$identity->country,
						self::RESOLVER_VERSION,
					),
				),
				providerOverride: $provider,
				providerMnoKey: $override->getProviderMnoKey(),
			);
		}

		$base = $this->safeResolve($rawPhone);
		if ($base === null || !$base->valid || !$base->region) {
			return new PhoneMnoResolutionDTO(
				identity: PhoneMnoIdentityDTO::invalid(),
			);
		}

		$cached = $this->cacheService->readFreshCache($key, self::RESOLVER_VERSION);
		if ($cached !== null && $cached->getMno() !== null && $cached->getMno() !== '') {
			return new PhoneMnoResolutionDTO(
				identity: new PhoneMnoIdentityDTO(
					valid: true,
					e164: $base->e164,
					national: $base->national,
					region: $cached->getRegion(),
					country: $cached->getCountry(),
					mno: $cached->getMno(),
					carrierHint: $cached->getCarrierHint(),
					confidence: $this->confidenceFromString($cached->getConfidence()),
					source: PhoneMnoResolutionSource::CACHE,
					verified: $cached->getVerified(),
				),
				providerOverride: $this->providerFromCache($cached),
				providerMnoKey: $cached->getProviderMnoKey(),
			);
		}

		$detection = $this->mnoDetectionRegistry->resolve(
			$base->region,
			(string)$base->national,
		);

		$detectedMno = $detection['mno'] ?? null;
		$confidence = $detection['confidence'];

		if ($detectedMno !== null && $confidence === ResolutionConfidence::HIGH) {
			$this->cacheService->writeCache(
				$key,
				$base->region,
				$this->countryFor($base->region),
				$detectedMno,
				$base->carrierHint,
				null,
				null,
				self::RESOLVER_VERSION,
				$confidence,
			);
		}

		return new PhoneMnoResolutionDTO(
			identity: new PhoneMnoIdentityDTO(
				valid: true,
				e164: $base->e164,
				national: $base->national,
				region: $base->region,
				country: $this->countryFor($base->region),
				mno: $detectedMno,
				carrierHint: $base->carrierHint,
				confidence: $confidence,
				source: PhoneMnoResolutionSource::DETECTION,
			),
		);
	}

	/**
	 * Memoize a user-CONFIRMED MNO for a phone number.
	 */
	public function rememberResolvedMno(
		?string $phone,
		?string $region,
		?string $country,
		?string $mno,
		?string $providerMnoKey = null,
		PaymentProvider $provider = PaymentProvider::DPO,
		ResolutionConfidence $confidence = ResolutionConfidence::HIGH,
	): void {
		if (!$this->isV2Enabled()) {
			return;
		}

		$key = self::normalizePhoneKey($phone);

		if ($key === null || $mno === null || trim($mno) === '') {
			return;
		}

		$providerMnoKey = $providerMnoKey !== null && trim($providerMnoKey) !== ''
			? trim($providerMnoKey)
			: null;

		$existing = $this->cacheService->readFreshCache($key, self::RESOLVER_VERSION);

		if (
			$existing?->getVerified()
			&& !$this->cacheService->isSameVerifiedRoutingIdentity(
				$existing,
				$mno,
				$country,
				$providerMnoKey,
				$provider,
			)
		) {
			$this->logger->warning(
				'[PhoneMnoResolver] Skipping cache write for conflicting verified routing identity',
				[
					'phone' => $key,
					'existing_mno' => $existing->getMno(),
					'new_mno' => $mno,
					'existing_country' => $existing->getCountry(),
					'new_country' => $country,
					'existing_provider' => $existing->getProvider(),
					'new_provider' => $provider->value,
					'existing_provider_mno_key' => $existing->getProviderMnoKey(),
					'new_provider_mno_key' => $providerMnoKey,
				],
			);

			return;
		}

		$this->cacheService->writeCache(
			$key,
			$region,
			$country,
			strtolower(trim($mno)),
			null,
			$provider,
			$providerMnoKey,
			self::RESOLVER_VERSION,
			$confidence,
			true,
		);
	}

	private function resolveLegacy(string $rawPhone): PhoneMnoResolutionDTO {
		$base = $this->safeResolve($rawPhone);

		if ($base === null || !$base->valid || !$base->region) {
			return new PhoneMnoResolutionDTO(
				identity: PhoneMnoIdentityDTO::invalid(),
			);
		}

		$detection = $this->mnoDetectionRegistry->resolve(
			$base->region,
			(string)$base->national,
		);

		return new PhoneMnoResolutionDTO(
			identity: new PhoneMnoIdentityDTO(
				valid: true,
				e164: $base->e164,
				national: $base->national,
				region: $base->region,
				country: $this->countryFor($base->region),
				mno: $detection['mno'] ?? null,
				carrierHint: $base->carrierHint,
				confidence: $detection['confidence'],
				source: PhoneMnoResolutionSource::DETECTION,
			),
		);
	}

	private function safeResolve(string $rawPhone): ?PaymentPhoneResolutionDTO {
		try {
			$dto = $this->phoneResolutionService->resolve($rawPhone);

			return $dto->valid ? $dto : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function providerFromCache(PhoneMnoCache $cache): ?PaymentProvider {
		if (!$cache->getVerified()) {
			return null;
		}

		$provider = $cache->getProvider();

		if ($provider === null || trim($provider) === '') {
			return null;
		}

		try {
			$providerEnum = PaymentProvider::from($provider);
		} catch (\ValueError) {
			$this->logger->warning('[PhoneMnoResolver] Invalid verified cache provider', [
				'provider' => $provider,
			]);

			return null;
		}

		if (
			$providerEnum === PaymentProvider::DPO
			&& $cache->getProviderMnoKey() !== null
			&& trim($cache->getProviderMnoKey()) !== ''
		) {
			return $providerEnum;
		}

		return null;
	}

	private function countryFor(?string $region): ?string {
		if ($region === null) {
			return null;
		}

		$ctx = $this->countryResolver->resolve($region);

		return $ctx?->country;
	}

	private function confidenceFromString(?string $value): ResolutionConfidence {
		if ($value === null) {
			return ResolutionConfidence::UNKNOWN;
		}

		return ResolutionConfidence::tryFrom($value)
			?? ResolutionConfidence::UNKNOWN;
	}

	private function isV2Enabled(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			'phone_mno_routing_v2_enabled',
			false,
		);
	}
}
