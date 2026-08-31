<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PhoneMnoOverride>
 */
class PhoneMnoOverrideMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_phone_overrides', PhoneMnoOverride::class);
	}

	/**
	 * Look up the active override for a normalized phone key.
	 *
	 * The key must be the canonical representation produced by
	 * PhoneMnoResolver::normalizePhoneKey() (same shape as
	 * Payment::phoneE164Digits). Returns null when there is no active
	 * override — the lookup never depends on carrier detection.
	 */
	public function findActiveByPhone(string $phoneE164Digits): ?PhoneMnoOverride {
		$qb = $this->db->getQueryBuilder();

		$qb->select('o.*')
			->from($this->getTableName(), 'o')
			->where(
				$qb->expr()->eq(
					'o.phone_e164_digits',
					$qb->createNamedParameter($phoneE164Digits)
				)
			)
			->andWhere(
				$qb->expr()->eq(
					'o.active',
					$qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
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
	 * All overrides, newest first. Admin listing only.
	 *
	 * @return PhoneMnoOverride[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('o.*')
			->from($this->getTableName(), 'o')
			->orderBy('o.created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Look up an override by normalized phone key REGARDLESS of active state.
	 * Used for duplicate handling (offer reactivation of an inactive row).
	 */
	public function findByPhone(string $phoneE164Digits): ?PhoneMnoOverride {
		$qb = $this->db->getQueryBuilder();

		$qb->select('o.*')
			->from($this->getTableName(), 'o')
			->where(
				$qb->expr()->eq(
					'o.phone_e164_digits',
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
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): PhoneMnoOverride {
		$qb = $this->db->getQueryBuilder();

		$qb->select('o.*')
			->from($this->getTableName(), 'o')
			->where(
				$qb->expr()->eq(
					'o.id',
					$qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)
				)
			);

		return $this->findEntity($qb);
	}
}
