<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\Types;

/**
 * @template-extends QBMapper<SignerSponsorship>
 */
class SignerSponsorshipMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct(
			$db,
			'gopaperless_signer_sponsorships',
			SignerSponsorship::class,
		);
	}

	public function findBySignRequestId(
		int $signRequestId,
	): ?SignerSponsorship {

		$qb = $this->db->getQueryBuilder();

		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->where(
				$qb->expr()->eq(
					's.sign_request_id',
					$qb->createNamedParameter(
						$signRequestId,
						Types::INTEGER
					)
				)
			)
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return SignerSponsorship[]
	 */
	public function findByFileId(
		int $fileId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->where(
				$qb->expr()->eq(
					's.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			);

		return $this->findEntities($qb);
	}

	/**
	 * @return SignerSponsorship[]
	 */
	public function findBySponsorUserId(
		string $userId,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->where(
				$qb->expr()->eq(
					's.sponsor_user_id',
					$qb->createNamedParameter(
						$userId,
						Types::STRING
					)
				)
			);

		return $this->findEntities($qb);
	}


	/**
	 * @return SignerSponsorship[]
	 */
	public function findByFileIdAndSponsorshipType(
		int $fileId,
		string $type,
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->where(
				$qb->expr()->eq(
					's.file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->eq(
					's.sponsorship_type',
					$qb->createNamedParameter(
						$type,
						Types::STRING
					)
				)
			);

		return $this->findEntities($qb);
	}


	/**
	 * @param int[] $signRequestIds
	 * @return SignerSponsorship[]
	 */
	public function findBySignRequestIds(
		array $signRequestIds,
	): array {

		if ($signRequestIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->where(
				$qb->expr()->in(
					's.sign_request_id',
					$qb->createNamedParameter(
						$signRequestIds,
						IQueryBuilder::PARAM_INT_ARRAY
					)
				)
			);

		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, SignerSponsorship>
	 */
	public function findIndexedBySignRequestIds(
		array $signRequestIds,
	): array {
		$result = [];

		foreach ($this->findBySignRequestIds($signRequestIds) as $item) {
			$result[$item->getSignRequestId()] = $item;
		}

		return $result;
	}

	public function deleteBySignRequestId(
		int $signRequestId,
	): void {

		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq(
					'sign_request_id',
					$qb->createNamedParameter(
						$signRequestId,
						Types::INTEGER,
					),
				),
			);

		$qb->executeStatement();
	}

	public function deleteByFileId(int $fileId): void
	{
		if ($fileId <= 0) {
			return;
		}

		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq(
					'file_id',
					$qb->createNamedParameter(
						$fileId,
						Types::INTEGER,
					),
				),
			);

			$qb->executeStatement();
	}
}
