<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version35000Date20260728053647 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_extended_accounts')) {
			return null;
		}

		$table = $schema->getTable('gopaperless_extended_accounts');

		// Government-issued identification number.
		if (!$table->hasColumn('id_number')) {
			$table->addColumn('id_number', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}

		return $schema;
	}
}
