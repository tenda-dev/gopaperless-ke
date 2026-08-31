<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\PaymentPhoneResolutionDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoIdentityDTO;
use Psr\Log\LoggerInterface;

/**
 * Looks up admin-configured phone/MNO overrides and maps them to identity DTOs.
 */
class PhoneMnoOverrideResolver {
	public function __construct(
		private PhoneMnoOverrideMapper $overrideMapper,
		private PaymentCountryResolver $countryResolver,
		private PhoneResolutionService $phoneResolutionService,
		private PhoneMnoCacheService $cacheService,
		private LoggerInterface $logger,
	) {
	}

	public function findOverride(string $key): ?PhoneMnoOverride {
		try {
			return $this->overrideMapper->findActiveByPhone($key);
		} catch (\Throwable $e) {
			$this->logger->error('[PhoneMnoOverrideResolver] override lookup failed', [
				'error' => $e->getMessage(),
			]);

			return null;
		}
	}

	public function buildOverrideIdentity(
		string $rawPhone,
		string $key,
		PhoneMnoOverride $override,
	): PhoneMnoIdentityDTO {
		$region = null;
		$national = null;
		$e164 = null;

		$base = $this->safeResolve($rawPhone);

		if ($base !== null && $base->valid && $base->region) {
			$region = $base->region;
			$national = $base->national;
			$e164 = $base->e164;
		} else {
			$lenient = $this->phoneResolutionService->parseLenient($rawPhone);

			if ($lenient !== null) {
				$region = $lenient['region'];
				$national = $lenient['national'];
				$e164 = $lenient['e164'] ?? $key;
			}
		}

		if ($region === null) {
			return PhoneMnoIdentityDTO::invalid();
		}

		return new PhoneMnoIdentityDTO(
			valid: true,
			e164: $e164 ?? $key,
			national: $national,
			region: $region,
			country: $this->countryFor($region),
			mno: $override->getMno(),
			carrierHint: null,
			confidence: ResolutionConfidence::HIGH,
			source: PhoneMnoResolutionSource::OVERRIDE,
		);
	}

	public function providerFromOverride(PhoneMnoOverride $override): ?PaymentProvider {
		try {
			return PaymentProvider::from($override->getProvider());
		} catch (\ValueError) {
			$this->logger->warning('[PhoneMnoOverrideResolver] Invalid override provider', [
				'provider' => $override->getProvider(),
			]);

			return null;
		}
	}

	public function isVerifiedOverrideMatch(
		string $key,
		PhoneMnoOverride $override,
		?PaymentProvider $provider,
		?string $country,
		string $resolverVersion,
	): bool {
		if ($provider === null) {
			return false;
		}

		$cache = $this->cacheService->readFreshCache($key, $resolverVersion);

		if ($cache === null || !$cache->getVerified()) {
			return false;
		}

		return $this->cacheService->isSameVerifiedRoutingIdentity(
			$cache,
			$override->getMno(),
			$country,
			$override->getProviderMnoKey(),
			$provider,
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

	private function countryFor(?string $region): ?string {
		if ($region === null) {
			return null;
		}

		$ctx = $this->countryResolver->resolve($region);

		return $ctx?->country;
	}
}
