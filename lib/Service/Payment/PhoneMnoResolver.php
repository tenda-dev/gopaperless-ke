<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\PhoneMnoCache;
use OCA\Libresign\Db\PhoneMnoCacheMapper;
use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\PaymentPhoneResolutionDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoIdentityDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoResolutionDTO;
use Psr\Log\LoggerInterface;

/**
 * Resolves a phone number to an MNO identity and optional authoritative
 * provider override
 *
 * Resolution precedence:
 *
 * active override > verified cache > valid cache > detection > fallback
 *
 * MNO identity and payment provider remain separate concerns.
 *
 * A phone override may additionally provide an explicit payment provider.
 * When present, the caller may use that provider instead of the provider
 * selected by MnoRoutingRegistry.
 *
 * The override lookup runs on a deterministic normalized key BEFORE any
 * libphonenumber validity gate, so an override can rescue a number that
 * libphonenumber would otherwise reject.
 */
class PhoneMnoResolver {
	/**
	 * Bump when MnoDetectionRegistry, the phone metadata/library, or the
	 * resolution logic changes. Cached rows stamped with a different value
	 * are treated as a miss and re-resolved.
	 */
	public const RESOLVER_VERSION = '1';

	/** Default cache TTL. Numbering plans move slowly; override via app config. */
	private const DEFAULT_TTL_SECONDS = 2592000; // 30 days

	private const TTL_CONFIG_KEY = 'phone_mno_cache_ttl_seconds';

	public function __construct(
		private PhoneMnoOverrideMapper $overrideMapper,
		private PhoneMnoCacheMapper $cacheMapper,
		private PhoneResolutionService $phoneResolutionService,
		private MnoDetectionRegistry $mnoDetectionRegistry,
		private PaymentCountryResolver $countryResolver,
		private PaymentDateTimeHelper $dateTimeHelper,
		private \OCP\IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Deterministic, library-free normalization used as the override/cache key.
	 *
	 * Produces the same representation stored in Payment::phoneE164Digits
	 * (leading '+' followed by digits), and works even when libphonenumber
	 * rejects the number.
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

	/**
	 * Resolve a raw phone number to an MNO identity and optional provider
	 * override.
	 */
	public function resolve(string $rawPhone): PhoneMnoResolutionDTO {
		$key = self::normalizePhoneKey($rawPhone);

		if ($key === null) {
			return new PhoneMnoResolutionDTO(
				identity: PhoneMnoIdentityDTO::invalid(),
			);
		}

		// 1. OVERRIDE — authoritative, consulted before any validity gate.
		$override = $this->findOverride($key);
		if ($override !== null) {
			$identity = $this->buildOverrideIdentity($rawPhone, $key, $override);

			if (!$identity->valid) {
				return new PhoneMnoResolutionDTO(
					identity: $identity,
				);
			}

			return new PhoneMnoResolutionDTO(
				identity: $identity,
				providerOverride: $this->providerFromOverride($override),
				providerMnoKey: $override->getProviderMnoKey(),
			);
		}

		// Base (strict) libphonenumber resolution for the normal paths.
		$base = $this->safeResolve($rawPhone);
		if ($base === null || !$base->valid || !$base->region) {
			return new PhoneMnoResolutionDTO(
				identity: PhoneMnoIdentityDTO::invalid(),
			);
		}

		// 2. CACHE — valid, fresh, matching resolver version.
		$cached = $this->readFreshCache($key);
		if ($cached !== null && $cached->getMno() !== null && $cached->getMno() !== '') {
			$providerOverride = $this->providerFromCache($cached);

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
				providerOverride: $providerOverride,
				providerMnoKey: $cached->getProviderMnoKey(),
			);
		}

		// 3. DETECTION — existing libphonenumber carrier hint + MNO registry.
		$detection = $this->mnoDetectionRegistry->resolve(
			$base->region,
			(string)$base->national,
		);

		$detectedMno = $detection['mno'] ?? null;

		/** @var ResolutionConfidence $confidence */
		$confidence = $detection['confidence'];


		// Write-through ONLY for confident, concrete detections. Ambiguous /
		// unknown results are never negative-cached: those go through the DPO
		// selection flow and are memoized from the user-confirmed MNO in
		// MobileMoneyChargeService (see rememberResolvedMno).
		if ($detectedMno !== null && $confidence === ResolutionConfidence::HIGH) {
			$this->writeCache(
				$key,
				$base->region,
				$this->countryFor($base->region),
				$detectedMno,
				$base->carrierHint,
				null,
				null,
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
	 *
	 * Called from the DPO deferred-charge step once the charge has been sent
	 * and payment metadata persisted. Best-effort: never throws into the
	 * money-moving path.
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
		$key = self::normalizePhoneKey($phone);

		if ($key === null || $mno === null || trim($mno) === '') {
			return;
		}

		$providerMnoKey = $providerMnoKey !== null && trim($providerMnoKey) !== ''
			? trim($providerMnoKey)
			: null;

		$existing = $this->readFreshCache($key);

		if (
			$existing?->getVerified()
			&& !$this->isSameVerifiedRoutingIdentity(
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

		$this->writeCache(
			$key,
			$region,
			$country,
			strtolower(trim($mno)),
			null,
			$provider,
			$providerMnoKey,
			$confidence,
		);
	}

	private function findOverride(string $key): ?PhoneMnoOverride {
		try {
			return $this->overrideMapper->findActiveByPhone($key);
		} catch (\Throwable $e) {
			$this->logger->error('[PhoneMnoResolver] override lookup failed', [
				'error' => $e->getMessage(),
			]);

			return null;
		}
	}

	private function buildOverrideIdentity(
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
			// Rescue: libphonenumber rejected the number but it may still be
			// parseable enough to recover geo. This is what lets an override
			// pull an otherwise-invalid number back into the routing flow.
			$lenient = $this->phoneResolutionService->parseLenient($rawPhone);

			if ($lenient !== null) {
				$region = $lenient['region'];
				$national = $lenient['national'];
				$e164 = $lenient['e164'] ?? $key;
			}
		}

		if ($region === null) {
			// No usable region even with the override — cannot route safely.
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

	private function providerFromOverride(PhoneMnoOverride $override): ?PaymentProvider {
		try {
			return PaymentProvider::from($override->getProvider());
		} catch (\ValueError) {
			$this->logger->warning('[PhoneMnoResolver] Invalid override provider', [
				'provider' => $override->getProvider(),
			]);

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
			return PaymentProvider::from($provider);
		} catch (\ValueError) {
			$this->logger->warning('[PhoneMnoResolver] Invalid verified cache provider', [
				'provider' => $provider,
			]);

			return null;
		}
	}

	private function safeResolve(string $rawPhone): ?PaymentPhoneResolutionDTO {
		try {
			$dto = $this->phoneResolutionService->resolve($rawPhone);

			return $dto->valid ? $dto : null;
		} catch (\Throwable $e) {
			// resolve() enforces a DTO invariant that throws on invalid input;
			// treat any failure here as "unresolved" and let callers fall back.
			return null;
		}
	}

	private function readFreshCache(string $key): ?PhoneMnoCache {
		try {
			$row = $this->cacheMapper->findByPhone($key);
		} catch (\Throwable $e) {
			return null;
		}

		if ($row === null) {
			return null;
		}

		// Resolver-version mismatch = miss.
		if ($row->getResolverVersion() !== self::RESOLVER_VERSION) {
			return null;
		}

		$resolvedAt = $row->getResolvedAt();

		if (!$resolvedAt instanceof \DateTimeInterface) {
			return null;
		}

		$age = $this->dateTimeHelper->nowImmutable()->getTimestamp()
			- $resolvedAt->getTimestamp();

		// Stale TTL = miss.
		if ($age > $this->ttlSeconds()) {
			return null;
		}

		return $row;
	}

	private function writeCache(
		string $key,
		?string $region,
		?string $country,
		string $mno,
		?string $carrierHint,
		?PaymentProvider $provider,
		?string $providerMnoKey,
		ResolutionConfidence $confidence,
		bool $verified = false,
	): void {
		try {
			$this->cacheMapper->store(
				$key,
				$region,
				$country,
				$mno,
				$carrierHint,
				$confidence->value,
				self::RESOLVER_VERSION,
				$this->dateTimeHelper->nowImmutable(),
				$provider?->value,
				$providerMnoKey,
				verified: $verified,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PhoneMnoResolver] cache write failed', [
				'error' => $e->getMessage(),
			]);
		}
	}

	private function isSameVerifiedRoutingIdentity(
		PhoneMnoCache $existing,
		string $mno,
		?string $country,
		?string $providerMnoKey,
		PaymentProvider $provider,
	): bool {
		return strtolower(trim((string)$existing->getMno())) === strtolower(trim($mno))
			&& strtolower(trim((string)$existing->getCountry())) === strtolower(trim((string)$country))
			&& strtolower(trim((string)$existing->getProvider())) === $provider->value
			&& strtolower(trim((string)$existing->getProviderMnoKey())) === strtolower(trim((string)$providerMnoKey));
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

	private function ttlSeconds(): int {
		return $this->appConfig->getValueInt(
			Application::APP_ID,
			self::TTL_CONFIG_KEY,
			self::DEFAULT_TTL_SECONDS,
		);
	}
}
