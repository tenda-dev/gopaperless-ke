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
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Low-level read/write access to the phone/MNO identity cache.
 *
 * Keeps resolver-version and TTL semantics in one place so the core resolver
 * only orchestrates decisions.
 */
class PhoneMnoCacheService {
	/** Default cache TTL. Numbering plans move slowly; override via app config. */
	private const DEFAULT_TTL_SECONDS = 2592000; // 30 days

	private const TTL_CONFIG_KEY = 'phone_mno_cache_ttl_seconds';

	public function __construct(
		private PhoneMnoCacheMapper $cacheMapper,
		private PaymentDateTimeHelper $dateTimeHelper,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Fetch a fresh, non-stale cache row for the normalized phone key.
	 */
	public function readFreshCache(string $key, string $resolverVersion): ?PhoneMnoCache {
		try {
			$row = $this->cacheMapper->findByPhone($key);
		} catch (\Throwable $e) {
			return null;
		}

		if ($row === null) {
			return null;
		}

		if ($row->getResolverVersion() !== $resolverVersion) {
			return null;
		}

		$resolvedAt = $row->getResolvedAt();

		if (!$resolvedAt instanceof \DateTimeInterface) {
			return null;
		}

		$age = $this->dateTimeHelper->nowImmutable()->getTimestamp()
			- $resolvedAt->getTimestamp();

		if ($age > $this->ttlSeconds()) {
			return null;
		}

		return $row;
	}

	/**
	 * Persist a resolved or verified MNO identity to the cache.
	 */
	public function writeCache(
		string $key,
		?string $region,
		?string $country,
		string $mno,
		?string $carrierHint,
		?PaymentProvider $provider,
		?string $providerMnoKey,
		string $resolverVersion,
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
				$resolverVersion,
				$this->dateTimeHelper->nowImmutable(),
				$provider?->value,
				$providerMnoKey,
				verified: $verified,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PhoneMnoCacheService] cache write failed', [
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Determine whether a verified cache row matches the proposed routing identity.
	 */
	public function isSameVerifiedRoutingIdentity(
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

	private function ttlSeconds(): int {
		return $this->appConfig->getValueInt(
			Application::APP_ID,
			self::TTL_CONFIG_KEY,
			self::DEFAULT_TTL_SECONDS,
		);
	}
}
