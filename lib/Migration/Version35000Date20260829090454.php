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
class Version35000Date20260829090454 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_phone_overrides')) {
			$table = $schema->createTable('gopaperless_phone_overrides');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Normalized digits-only E.164 (leading '+'), matching Payment::phoneE164Digits.
			$table->addColumn('phone_e164_digits', 'string', [
				'notnull' => true,
				'length' => 32,
			]);

			// Carrier identity, e.g. "safaricom". Fed to MnoRoutingRegistry; never a rail.
			$table->addColumn('mno', 'string', [
				'notnull' => true,
				'length' => 32,
			]);

			$table->addColumn('active', 'boolean', [
				'notnull' => true,
				'default' => true,
			]);

			$table->addColumn('note', 'text', [
				'notnull' => false,
			]);

			$table->addColumn('created_by', 'string', [
				'notnull' => false,
				'length' => 64,
			]);

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);

			$table->addColumn('updated_at', 'datetime', [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);

			$table->addUniqueIndex(['phone_e164_digits'], 'uniq_gp_phone_override');
			$table->addIndex(['active'], 'idx_gp_phone_ovr_active');
		}

		return $schema;
	}

}
