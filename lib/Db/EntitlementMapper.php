<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\EntitlementType;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\Types;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Entitlement>
 */
class EntitlementMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_entitlements', Entitlement::class);
	}

	/**
	 * Find entitlement by ID
	 * @throws Exception
	 */
	public function findById(int $id): ?Entitlement {

		$qb = $this->db->getQueryBuilder();

		$qb->select('e.*')
			->from($this->getTableName(), 'e')
			->where(
				$qb->expr()->eq(
					'e.id',
					$qb->createNamedParameter($id, Types::INTEGER)
				)
			)
			->setMaxResults(1);

		try {
			/** @var Entitlement $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find entitlement by ID and acquire a row lock.
	 *
	 * Acquires a pessimistic lock for transactional mutation paths such as
	 * reservation, release and settlement. Read-only callers should continue
	 * using findById() to avoid unnecessary lock contention.
	 *
	 * The caller is responsible for owning the transaction.
	 *
	 * @throws Exception
	 */
	public function findByIdForUpdate(int $id): ?Entitlement {
		$qb = $this->db->getQueryBuilder();

		$qb->select('e.*')
			->from($this->getTableName(), 'e')
			->where(
				$qb->expr()->eq(
					'e.id',
					$qb->createNamedParameter(
						$id,
						Types::INTEGER,
					),
				),
			)
			->setMaxResults(1)
			->forUpdate();

		try {
			/** @var Entitlement $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Fetch all entitlements for a user + product
	 *
	 * Used by service to determine usable entitlements
	 * @throws Exception
	 */
	public function findByUserAndProduct(
		string $userId,
		string $productCode,
	): array {
		return $this->findActiveByUserAndProduct(
			$userId,
			$productCode
		);
	}

	/**
	 * 🔥 Optional optimization (can skip for now):
	 * Fetch only potentially usable entitlements
	 *
	 * NOTE:
	 * - Does NOT handle expiry logic fully (handled in entity/service)
	 * - Helps reduce filtering in PHP
	 * @throws Exception
	 */
	public function findPotentiallyUsable(string $userId, string $productCode): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('e.*')
			->from($this->getTableName(), 'e')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'e.user_id',
						$qb->createNamedParameter($userId, Types::STRING)
					),
					$qb->expr()->eq(
						'e.product_code',
						$qb->createNamedParameter($productCode, Types::STRING)
					),
					// remaining_uses > 0 OR NULL (unlimited)
					$qb->expr()->orX(
						$qb->expr()->isNull('e.remaining_uses'),
						$qb->expr()->gt(
							'e.remaining_uses',
							$qb->createNamedParameter(0, Types::INTEGER)
						)
					)
				)
			)
			->orderBy('e.created_at', 'ASC'); // oldest first (FIFO consumption)

		return $this->findEntities($qb);
	}


	public function findActiveByUserAndProduct(
		string $userId,
		string $productCode,
	): array {

		$qb = $this->db->getQueryBuilder();

		// Consumption/reservation priority: use complimentary or recurring credits
		// before purchased credits, preserving the user's prepaid balance:
		//   0 PROMOTIONAL   — signup bonuses and promotional credits
		//   1 ADMIN_GRANTED — credits granted by an administrator
		//   2 SUBSCRIPTION  — subscription credits, which may renew or expire
		//   3 PAY_AS_YOU_GO — purchased credits, preserved until last
		//   4 unknown types — deterministic fallback for future entitlement types
		//
		// Priority is explicit; alphabetical ordering is not the business rule.
		// Within each priority, consume older entitlements first (FIFO).
		// This shared selector governs both consumption and sponsorship reservation;
		// callers MUST not introduce their own ordering.
		// The CASE expression uses bound parameters and is compatible with the
		// database platforms supported by Nextcloud.

		$typePriority = $qb->createFunction(sprintf(
			'CASE e.type'
			. ' WHEN %s THEN 0'
			. ' WHEN %s THEN 1'
			. ' WHEN %s THEN 2'
			. ' WHEN %s THEN 3'
			. ' ELSE 4 END',
			$qb->createNamedParameter(EntitlementType::PROMOTIONAL->value, Types::STRING),
			$qb->createNamedParameter(EntitlementType::ADMIN_GRANTED->value, Types::STRING),
			$qb->createNamedParameter(EntitlementType::SUBSCRIPTION->value, Types::STRING),
			$qb->createNamedParameter(EntitlementType::PAY_AS_YOU_GO->value, Types::STRING),
		));

		$qb->select('e.*')
			->from($this->getTableName(), 'e')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'e.user_id',
						$qb->createNamedParameter(
							$userId,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'e.product_code',
						$qb->createNamedParameter(
							$productCode,
							Types::STRING
						)
					)
				)
			)
			->orderBy($typePriority, 'ASC')
			->addOrderBy('e.created_at', 'ASC');

		return $this->findEntities($qb);
	}


	/**
	 * @return list<Entitlement>
	 * @throws Exception
	 */
	public function findActiveByUserProductAndType(
		string $userId,
		string $productCode,
		string $type,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('e.*')
			->from($this->getTableName(), 'e')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'e.user_id',
						$qb->createNamedParameter(
							$userId,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'e.product_code',
						$qb->createNamedParameter(
							$productCode,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'e.type',
						$qb->createNamedParameter(
							$type,
							Types::STRING
						)
					)
				)
			)
			->orderBy('e.created_at', 'ASC');

		return $this->findEntities($qb);
	}


	/**
	 * @return list<Entitlement>
	 * @throws Exception
	 */
	public function findActiveByUserProductAndEntitlementType(
		string $userId,
		string $productCode,
		EntitlementType $type,
	): array {
		return $this->findActiveByUserProductAndType(
			$userId,
			$productCode,
			$type->value
		);
	}
}
