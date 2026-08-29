<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PhoneMnoCache>
 */
class PhoneMnoCacheMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_phone_mno_cache', PhoneMnoCache::class);
	}

	/**
	 * Fetch a cached resolution row by normalized phone key.
	 *
	 * Freshness (TTL) and resolver-version validity are the resolver's
	 * concern; this only returns the row (or null).
	 */
	public function findByPhone(string $phoneE164Digits): ?PhoneMnoCache {
		$qb = $this->db->getQueryBuilder();

		$qb->select('c.*')
			->from($this->getTableName(), 'c')
			->where(
				$qb->expr()->eq(
					'c.phone_e164_digits',
					$qb->createNamedParameter($phoneE164Digits)
				)
			)
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Upsert a resolution row, keyed by the normalized phone number.
	 *
	 * Refreshes the existing row when present (no duplicate keys) or
	 * inserts a new one. The unique index on phone_e164_digits is the
	 * backstop against duplicates.
	 */
	public function store(
		string $phoneE164Digits,
		?string $region,
		?string $country,
		?string $mno,
		?string $carrierHint,
		string $confidence,
		string $resolverVersion,
		\DateTimeInterface $now,
	): PhoneMnoCache {
		$timestamp = $now instanceof \DateTime
			? $now
			: \DateTime::createFromInterface($now);

		$existing = $this->findByPhone($phoneE164Digits);

		if ($existing !== null) {
			$existing->setRegion($region);
			$existing->setCountry($country);
			$existing->setMno($mno);
			$existing->setCarrierHint($carrierHint);
			$existing->setConfidence($confidence);
			$existing->setResolverVersion($resolverVersion);
			$existing->setResolvedAt($timestamp);
			$existing->setUpdatedAt($timestamp);

			return $this->update($existing);
		}

		$entity = new PhoneMnoCache();
		$entity->setPhoneE164Digits($phoneE164Digits);
		$entity->setRegion($region);
		$entity->setCountry($country);
		$entity->setMno($mno);
		$entity->setCarrierHint($carrierHint);
		$entity->setConfidence($confidence);
		$entity->setResolverVersion($resolverVersion);
		$entity->setResolvedAt($timestamp);
		$entity->setCreatedAt($timestamp);
		$entity->setUpdatedAt($timestamp);

		return $this->insert($entity);
	}
}
