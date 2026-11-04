<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\Comments\ICommentsManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<IdentifyMethod>
 */
class IdentifyMethodMapper extends QBMapper {
	/**
	 * @var IdentifyMethod[][]
	 */
	private array $methodsBySignRequest = [];
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'libresign_identify_method');
	}

	/**
	 * @return IdentifyMethod[]
	 */
	public function getIdentifyMethodsFromSignRequestId(int $signRequestId): array {
		if (!empty($this->methodsBySignRequest[$signRequestId])) {
			return $this->methodsBySignRequest[$signRequestId];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('im.*')
			->from('libresign_identify_method', 'im')
			->where(
				$qb->expr()->eq('im.sign_request_id', $qb->createNamedParameter($signRequestId, IQueryBuilder::PARAM_INT))
			);
		$cursor = $qb->executeQuery();
		$this->methodsBySignRequest[$signRequestId] = [];
		while ($row = $cursor->fetch()) {
			/** @var IdentifyMethod */
			$this->methodsBySignRequest[$signRequestId][] = $this->mapRowToEntity($row);
		}
		return $this->methodsBySignRequest[$signRequestId];
	}

	/**
	 * @param int[] $signRequestIds
	 * @return array<int, IdentifyMethod[]>
	 */
	public function getIdentifyMethodsFromSignRequestIds(array $signRequestIds): array {
		if (empty($signRequestIds)) {
			return [];
		}

		$missing = array_diff($signRequestIds, array_keys($this->methodsBySignRequest));

		if (!empty($missing)) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('im.*')
				->from('libresign_identify_method', 'im')
				->where($qb->expr()->in('im.sign_request_id', $qb->createNamedParameter($missing, IQueryBuilder::PARAM_INT_ARRAY)));

			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$entity = $this->mapRowToEntity($row);
				$signRequestId = $entity->getSignRequestId();
				$this->methodsBySignRequest[$signRequestId][] = $entity;
			}
			$cursor->closeCursor();
		}

		return array_map(fn ($id) => $this->methodsBySignRequest[$id] ?? [], $signRequestIds);
	}

	public function neutralizeDeletedUser(string $userId, string $displayName): void {
		$update = $this->db->getQueryBuilder();
		$qb = $this->db->getQueryBuilder();
		$qb->select('im.id')
			->addSelect('im.metadata')
			->from('libresign_identify_method', 'im')
			->where($qb->expr()->in('im.identifier_key', $qb->createNamedParameter(['account', 'email'], IQueryBuilder::PARAM_STR_ARRAY), IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->eq('im.identifier_value', $qb->createNamedParameter($userId)));
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			if (is_string($row['metadata']) && !empty($row['metadata'])) {
				$row['metadata'] = json_decode($row['metadata'], true);
			}
			$row['metadata']['deleted_account'] = [
				'account' => $userId,
				'display_name' => $displayName,
			];
			$update->update('libresign_identify_method')
				->set('identifier_value', $update->createNamedParameter(ICommentsManager::DELETED_USER))
				->set('metadata', $update->createNamedParameter($row['metadata'], IQueryBuilder::PARAM_JSON))
				->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
			$update->executeStatement();
		}
	}

	public function deleteBySignRequestId(int $signRequestId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('libresign_identify_method')
			->where(
				$qb->expr()->eq('sign_request_id', $qb->createNamedParameter($signRequestId, IQueryBuilder::PARAM_INT))
			)
			->executeStatement();
		unset($this->methodsBySignRequest[$signRequestId]);
	}

	/**
	 * Search the requester's own historical signers by identifier value or
	 * display name.
	 *
	 * Requester scoping is enforced on the MAIN query (f.user_id = :userId).
	 * The previous implementation scoped only inside an embedded sub-query joined
	 * via createFunction; that scoping did not take effect at runtime (the outer
	 * query carried no ownership filter), which leaked other requesters' signer
	 * history across accounts. Rebuilt on a single query builder so there is no
	 * cross-builder named-parameter collision and ownership is guaranteed.
	 *
	 * Results are de-duplicated per (identifier_key, identifier_value); the
	 * display name is the requester's own label for that identity.
	 *
	 * @return array<string, string>[]
	 */
	public function searchByIdentifierValue(string $search, string $userId, string $method, int $limit = 20, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter($search) . '%';

		$qb->select('im.identifier_key', 'im.identifier_value')
			->selectAlias($qb->func()->max('sr.display_name'), 'display_name')
			->from('libresign_identify_method', 'im')
			->join('im', 'libresign_sign_request', 'sr',
				$qb->expr()->eq('sr.id', 'im.sign_request_id')
			)
			->join('sr', 'libresign_file', 'f',
				$qb->expr()->eq('f.id', 'sr.file_id')
			)
			->where($qb->expr()->eq('f.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->neq('im.identifier_value', $qb->createNamedParameter('deleted_users')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->iLike('im.identifier_value', $qb->createNamedParameter($like)),
					$qb->expr()->iLike('sr.display_name', $qb->createNamedParameter($like))
				)
			);
		if (!empty($method)) {
			$qb->andWhere($qb->expr()->eq('im.identifier_key', $qb->createNamedParameter($method)));
		}
		$qb->groupBy('im.identifier_key')
			->addGroupBy('im.identifier_value')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$cursor = $qb->executeQuery();
		$return = [];
		while ($row = $cursor->fetch()) {
			$return[] = $row;
		}
		$cursor->closeCursor();
		return $return;
	}
}
