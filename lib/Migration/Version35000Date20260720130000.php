<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seeds a default SIGN_DOCUMENT product if none exists.
 *
 * This prevents entitlement/coverage endpoints from failing on fresh
 * installations where the product table exists but has not been populated
 * through the admin product-management UI.
 */
class Version35000Date20260720130000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_products')) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$existing = $qb
			->select('id')
			->from('gopaperless_products')
			->where(
				$qb->expr()->eq(
					'code',
					$qb->createNamedParameter('SIGN_DOCUMENT', IQueryBuilder::PARAM_STR),
				),
			)
			->andWhere(
				$qb->expr()->eq(
					'is_default',
					$qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				),
			)
			->executeQuery()
			->fetchOne();

		if ($existing !== false) {
			return;
		}

		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$qb = $this->connection->getQueryBuilder();
		$qb
			->insert('gopaperless_products')
			->values([
				'code' => $qb->createNamedParameter('SIGN_DOCUMENT', IQueryBuilder::PARAM_STR),
				'name' => $qb->createNamedParameter('Sign Document', IQueryBuilder::PARAM_STR),
				'amount' => $qb->createNamedParameter(8000, IQueryBuilder::PARAM_INT),
				'currency' => $qb->createNamedParameter('KES', IQueryBuilder::PARAM_STR),
				'uses' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'version' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'is_default' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
			])
			->executeStatement();
	}
}
