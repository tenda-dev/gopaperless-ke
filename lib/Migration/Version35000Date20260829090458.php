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


class Version35000Date20260829090458 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_phone_mno_cache')) {
			$table = $schema->createTable('gopaperless_phone_mno_cache');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Normalized digits-only E.164 (leading '+'), matching Payment::phoneE164Digits.
			$table->addColumn('phone_e164_digits', 'string', [
				'notnull' => true,
				'length' => 32,
			]);

			$table->addColumn('region', 'string', [
				'notnull' => false,
				'length' => 4,
			]);

			$table->addColumn('country', 'string', [
				'notnull' => false,
				'length' => 32,
			]);

			// Detected MNO identity. Never the payment rail (DPO/Daraja).
			$table->addColumn('mno', 'string', [
				'notnull' => false,
				'length' => 32,
			]);

			$table->addColumn('carrier_hint', 'string', [
				'notnull' => false,
				'length' => 64,
			]);

			$table->addColumn('confidence', 'string', [
				'notnull' => true,
				'length' => 16,
				'default' => 'unknown',
			]);

			$table->addColumn('resolver_version', 'string', [
				'notnull' => true,
				'length' => 32,
			]);

			$table->addColumn('resolved_at', 'datetime', [
				'notnull' => true,
			]);

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);

			$table->addColumn('updated_at', 'datetime', [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);

			$table->addUniqueIndex(['phone_e164_digits'], 'uniq_gp_ph_mno_cache');
			$table->addIndex(['resolved_at'], 'idx_gp_ph_mno_resolved');
			$table->addIndex(['resolver_version'], 'idx_gp_ph_mno_version');
		}

		return $schema;
	}
}
