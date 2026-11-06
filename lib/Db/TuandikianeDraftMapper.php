<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TuandikianeDraft>
 */
class TuandikianeDraftMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_tuandikiane', TuandikianeDraft::class);
	}

	public function findByReference(string $reference): ?TuandikianeDraft {
		return $this->findOneBy('reference', $reference, IQueryBuilder::PARAM_STR);
	}

	public function findByDraftUuid(string $uuid): ?TuandikianeDraft {
		return $this->findOneBy('draft_uuid', $uuid, IQueryBuilder::PARAM_STR);
	}

	public function findByFileId(int $fileId): ?TuandikianeDraft {
		if ($fileId <= 0) {
			return null;
		}
		return $this->findOneBy('file_id', $fileId, IQueryBuilder::PARAM_INT);
	}

	/**
	 * The same, holding the row until the caller's transaction ends, so two
	 * sends or two bindings of one draft run one after the other.
	 */
	public function findByDraftUuidForUpdate(string $uuid): ?TuandikianeDraft {
		return $this->findOneBy('draft_uuid', $uuid, IQueryBuilder::PARAM_STR, true);
	}

	/**
	 * Drafts nobody sent before they expired, oldest first.
	 *
	 * @return list<TuandikianeDraft>
	 */
	public function findExpiredDrafts(\DateTimeInterface $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(TuandikianeDraft::STATUS_DRAFT)))
			->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, Types::DATETIME)))
			->orderBy('expires_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	private function findOneBy(string $column, string|int $value, int $type, bool $forUpdate = false): ?TuandikianeDraft {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)))
			->setMaxResults(1);
		if ($forUpdate) {
			$qb->forUpdate();
		}
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}
}
