<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use DateTimeImmutable;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\DpoMobileOption;
use OCA\Libresign\Db\DpoMobileOptionMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * DPO mobile options are treated as a terminal-wide cache snapshot.
 *
 * DPO currently returns mobile payment options across all configured
 * countries for the terminal. Cached snapshots are therefore refreshed
 * atomically for affected countries to avoid stale MNO mappings causing
 * ChargeTokenMobile failures.
 */
final class DpoMobileOptionsService
{
	private const MOBILE_OPTIONS_CACHE_UPDATED_AT =
	'dpo_mobile_options_cache_updated_at';

	private const CACHE_TTL_HOURS = 12;

	public function __construct(
		private DpoProvider $dpo,
		private DpoMobileOptionMapper $mapper,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {}

	/**
	 * Get mobile payment options for a transaction.
	 *
	 * FLOW:
	 * 1. Return cached options when available and cache is valid
	 * 2. Fetch latest snapshot from DPO when cache is stale or missing
	 * 3. Replace affected country snapshots atomically
	 * 4. Update cache metadata
	 * 5. Return normalized options
	 */
	public function getOptions(string $providerReference, string $country): array
	{

		$options = [];

		if (!$this->isCacheExpired()) {
			$options = $this->mapper->findByCountry($country);
		}

		if (!empty($options)) {
			return $this->toArray($options);
		}

		// Cache miss or stale cache → refresh from DPO
		$fetched = $this->dpo->getMobileOptions($providerReference);


		if (empty($fetched)) {
			throw new RuntimeException(
				'No mobile payment options returned from DPO'
			);
		}

		$this->logger->info(
			'Refreshed DPO mobile options cache',
			[
				'country' => $country,
				'providerReference' => $providerReference,
				'count' => count($fetched),
				'countries' => array_values(array_unique(
					array_map(
						static fn(array $option): string =>
						strtolower($option['country']),
						$fetched,
					),
				)),
			],
		);

		// Replace cached country snapshots
		$entities = [];
		$now = $this->now();

		foreach ($fetched as $opt) {

			$entity = new DpoMobileOption();

			$entity->setProvider($this->normaliseProvider($opt['provider'] ?? null));
			$entity->setCountry(strtolower($opt['country'] ?? $country));
			$entity->setCountryCode($opt['countryCode'] ?? null);
			$entity->setPrefix($opt['prefix'] ?? null);
			$entity->setCurrency($opt['currency'] ?? null);
			$entity->setInstructions($opt['instructions'] ?? null);
			$entity->setLogo($opt['logo'] ?? null);

			// store raw payload (string)
			$entity->setRawPayload(
				json_encode($opt, JSON_THROW_ON_ERROR)
			);

			// timestamps
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);

			$entities[] = $entity;
		}

		try {
			$this->mapper->replaceSnapshot($entities);

			$this->appConfig->setValueString(
				Application::APP_ID,
				self::MOBILE_OPTIONS_CACHE_UPDATED_AT,
				$now,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to refresh DPO mobile options cache',
				[
					'providerReference' => $providerReference,
					'country' => $country,
					'error' => $e->getMessage(),
					'exception' => get_class($e),
				],
			);

			throw $e;
		}

		// Return normalised snapshot
		return $this->toArray(
			$this->mapper->findByCountry($country)
		);
	}

	// -----------------------------
	// Helpers
	// -----------------------------

	private function normaliseProvider(?string $provider): string
	{
		return strtolower(trim((string) $provider));
	}

	private function now(): string
	{
		return (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		))->format(DATE_ATOM);
	}

	/**
	 * Convert entities → API-safe array
	 */
	private function toArray(array $entities): array
	{
		return array_map(
			static function (DpoMobileOption $option): array {
				return [
					'provider' => $option->getProvider(),
					'country' => $option->getCountry(),
					'countryCode' => $option->getCountryCode(),
					'prefix' => $option->getPrefix(),
					'currency' => $option->getCurrency(),
					'instructions' => $option->getInstructions(),
					'logo' => $option->getLogo(),
				];
			},
			$entities,
		);
	}


	private function isCacheExpired(): bool
	{
		$lastUpdated = $this->appConfig->getValueString(
			Application::APP_ID,
			self::MOBILE_OPTIONS_CACHE_UPDATED_AT,
			''
		);

		if ($lastUpdated === '') {
			return true;
		}

		try {
			$lastSync = new DateTimeImmutable($lastUpdated);

			return $lastSync->modify(
				'+' . self::CACHE_TTL_HOURS . ' hours'
			) <= new DateTimeImmutable();
		} catch (\Throwable) {

			// Corrupt config → force refresh
			return true;
		}
	}
}
