<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\Types;
use OCP\IDBConnection;

/**
 * Persistence layer for extended account state.
 *
 * The absence of a row is a valid business state representing a
 * grandfathered account. Lookup methods therefore return null
 * instead of treating a missing row as exceptional.
 */
class ExtendedAccountMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct(
			$db,
			'gopaperless_extended_accounts',
			ExtendedAccount::class,
		);
	}

	public function findByUserId(
		string $userId,
	): ?ExtendedAccount {

		$qb = $this->db->getQueryBuilder();

		$qb->select('a.*')
			->from($this->getTableName(), 'a')
			->where(
				$qb->expr()->eq(
					'a.user_id',
					$qb->createNamedParameter(
						$userId,
						Types::STRING,
					),
				),
			)
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException
		) {
			return null;
		}
	}

	/**
	 * @return ExtendedAccount[]
	 * @throws Exception
	 */
	public function findByPaidCertificate(
		bool $paid,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('a.*')
			->from($this->getTableName(), 'a')
			->where(
				$qb->expr()->eq(
					'a.paid_certificate',
					$qb->createNamedParameter(
						$paid,
						Types::BOOLEAN,
					),
				),
			)
			->orderBy('a.created_at', 'ASC');

		return $this->findEntities($qb);
	}
}
