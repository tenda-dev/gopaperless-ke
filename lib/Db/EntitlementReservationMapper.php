<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\DB\Types;
use OCP\DB\Exception;

class EntitlementReservationMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct(
			$db,
			'gopaperless_entitlement_reservations',
			EntitlementReservation::class,
		);
	}

	/**
	 * @return EntitlementReservation[]
	 * @throws Exception
	 */
	public function findByFileId(
		int $fileId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			)
			->orderBy('r.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	public function findBySignRequestId(
		int $signRequestId,
	): ?EntitlementReservation {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.sign_request_id',
					$qb->createNamedParameter(
						$signRequestId,
						Types::INTEGER
					)
				)
			)
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {
			return null;
		}
	}

	public function findActiveBySignRequestId(
		int $signRequestId,
	): ?EntitlementReservation {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.sign_request_id',
					$qb->createNamedParameter(
						$signRequestId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'r.released_at'
				)
			)
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * @return EntitlementReservation[]
	 * @throws Exception
	 */
	public function findByEntitlementId(
		int $entitlementId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.entitlement_id',
					$qb->createNamedParameter(
						$entitlementId,
						Types::INTEGER
					)
				)
			)
			->orderBy('r.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return EntitlementReservation[]
	 * @throws Exception
	 */
	public function findActiveByFileId(
		int $fileId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'r.released_at'
				)
			)
			->orderBy('r.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return EntitlementReservation[]
	 * @throws Exception
	 */
	public function findActiveBySponsorUserId(
		string $userId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.sponsor_user_id',
					$qb->createNamedParameter(
						$userId,
						Types::STRING
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'r.released_at'
				)
			)
			->orderBy('r.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Soft release all reservations for a workflow.
	 *
	 * @throws Exception
	 */
	public function releaseByFileId(
		int $fileId,
		\DateTimeInterface $releasedAt,
	): int {

		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName(), 'r')
			->set(
				'released_at',
				$qb->createNamedParameter(
					$releasedAt,
					Types::DATETIME
				)
			)
			->where(
				$qb->expr()->eq(
					'r.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'r.released_at'
				)
			);

		return $qb->executeStatement();
	}

	/**
	 * @return int
	 * @throws Exception
	 */
	public function getReservedQuantityForFile(
		int $fileId,
	): int {

		$qb = $this->db->getQueryBuilder();

		$qb->select(
			$qb->func()->sum('r.quantity') . ' total'
		)
			->from($this->getTableName(), 'r')
			->where(
				$qb->expr()->eq(
					'r.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'r.released_at'
				)
			);

		$result = $qb->executeQuery()->fetchOne();

		return (int)($result ?: 0);
	}
}
