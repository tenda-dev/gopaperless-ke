<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Tuandikiane keeps its own drafts now and reaches GoPaperless through the
 * signing API, so the drafts table Version35000Date20261106120000 made on
 * instances that ran it goes.
 */
class Version35000Date20261113120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('gopaperless_tuandikiane')) {
			return null;
		}
		$schema->dropTable('gopaperless_tuandikiane');
		return $schema;
	}
}
