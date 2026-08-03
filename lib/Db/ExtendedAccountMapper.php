<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCA\Libresign\Service\Encryption\IdNumberEncryptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
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
 *
 * The id_number column holds AES-256-GCM ciphertext. Encryption is
 * applied transparently on write and decryption on read, so callers
 * only ever see plaintext on the entity.
 *
 * @template-extends QBMapper<ExtendedAccount>
 */
class ExtendedAccountMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private IdNumberEncryptionService $idNumberEncryption,
	) {
		parent::__construct(
			$db,
			'gopaperless_extended_accounts',
			ExtendedAccount::class,
		);
	}

	/**
	 * @param ExtendedAccount $entity
	 */
	#[\Override]
	public function insert(Entity $entity): ExtendedAccount {
		return $this->withEncryptedIdNumber(
			$entity,
			fn (): ExtendedAccount => parent::insert($entity),
		);
	}

	/**
	 * @param ExtendedAccount $entity
	 */
	#[\Override]
	public function update(Entity $entity): ExtendedAccount {
		return $this->withEncryptedIdNumber(
			$entity,
			fn (): ExtendedAccount => parent::update($entity),
		);
	}

	#[\Override]
	protected function mapRowToEntity(array $row): ExtendedAccount {
		/** @var ExtendedAccount $entity */
		$entity = parent::mapRowToEntity($row);

		$entity->setIdNumber(
			$this->idNumberEncryption->decrypt(
				$entity->getIdNumber(),
			),
		);

		return $entity;
	}

	/**
	 * Runs a persistence operation with id_number encrypted, then
	 * restores the plaintext on the entity so in-memory state always
	 * reflects what callers set.
	 *
	 * @param ExtendedAccount $entity
	 */
	private function withEncryptedIdNumber(
		ExtendedAccount $entity,
		\Closure $operation,
	): ExtendedAccount {

		$plaintext = $entity->getIdNumber();

		$entity->setIdNumber(
			$this->idNumberEncryption->encrypt($plaintext),
		);

		try {
			return $operation();
		} finally {
			$entity->setIdNumber($plaintext);
		}
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
			DoesNotExistException|
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
