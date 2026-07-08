<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version35000Date20260706205959 extends SimpleMigrationStep
{


	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();
		if (!$schema->hasTable('gopaperless_entitlement_reservations')) {

			$table = $schema->createTable(
				'gopaperless_entitlement_reservations'
			);

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// entitlement being reserved
			$table->addColumn('entitlement_id', 'integer', [
				'notnull' => true,
			]);

			// LibreSign workflow/file
			$table->addColumn('file_id', 'integer', [
				'notnull' => true,
			]);

			$table->addColumn('sign_request_id', 'integer', [
				'notnull' => true,
			]);

			// how many uses reserved
			$table->addColumn('quantity', 'integer', [
				'notnull' => true,
				'default' => 1,
			]);

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);

			$table->addColumn('sponsor_user_id', 'string', [
				'length' => 64,
				'notnull' => true,
			]);

			$table->addColumn('released_at', 'datetime', [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);

			$table->addIndex(
				['entitlement_id'],
				'gp_res_entitlement_idx'
			);

			$table->addIndex(
				['file_id', 'released_at'],
				'gp_res_file_rel_idx'
			);

			$table->addIndex(
				['sign_request_id', 'released_at'],
				'gp_res_sr_rel_idx'
			);

			$table->addIndex(
				['file_id', 'sign_request_id'],
				'gp_res_file_sr_idx'
			);

			$table->addIndex(
				['sponsor_user_id'],
				'gp_res_user_idx'
			);
		}
		return $schema;
	}
}
