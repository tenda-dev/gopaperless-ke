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
 * ReconcileUnconsumedSignaturesJob pages sign requests by
 * (signed, id) every 10 minutes; without an index each page is a
 * full-table scan plus filesort on a growing table.
 */
class Version36000Date20261123120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('libresign_sign_request');
		if ($table->hasIndex('sign_request_signed_idx')) {
			return null;
		}
		$table->addIndex(['signed', 'id'], 'sign_request_signed_idx');
		return $schema;
	}
}
