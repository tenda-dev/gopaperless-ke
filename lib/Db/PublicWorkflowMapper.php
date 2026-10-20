<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Types;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PublicWorkflow>
 */
class PublicWorkflowMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_public_workflows', PublicWorkflow::class);
	}

	/**
	 * Resolve a workflow by the SHA-256 hash of its capability token.
	 *
	 * The lookup is by the unique hash index; it does not interpret status or
	 * expiry — the service layer decides validity. Returns null when no row
	 * matches, so callers reveal nothing about whether a token exists.
	 */
	public function findByTokenHash(string $tokenHash): ?PublicWorkflow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash, Types::STRING)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Resolve a workflow by token hash and acquire a pessimistic row lock.
	 *
	 * Must be called inside a transaction (forUpdate() only locks within one).
	 * Serialises competing uploads for the same workflow: the second request
	 * blocks here until the first commits, then observes the claimed file_uuid.
	 * Mirrors EntitlementMapper::findByIdForUpdate. The caller owns the
	 * transaction. Real row locking applies on MariaDB/MySQL (staging + prod);
	 * on SQLite forUpdate() is a no-op, so this does not serialise there.
	 */
	public function findByTokenHashForUpdate(string $tokenHash): ?PublicWorkflow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash, Types::STRING)))
			->setMaxResults(1)
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}
}
