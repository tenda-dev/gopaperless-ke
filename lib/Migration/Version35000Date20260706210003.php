<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\Enum\EntitlementType;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version35000Date20260706210003 extends SimpleMigrationStep {

	#[Override]
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {

		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_entitlements')) {
			return $schema;
		}

		$table = $schema->getTable('gopaperless_entitlements');

		// ------------------------------------------------------------------
		// Reserved Uses
		//
		// Credits temporarily reserved for in-flight workflows.
		//
		// Example:
		// Remaining: 10
		// Reserved: 3
		// Available: 7
		// ------------------------------------------------------------------
		if (!$table->hasColumn('reserved_uses')) {
			$table->addColumn('reserved_uses', 'integer', [
				'notnull' => false,
				'default' => 0,
			]);
		}

		// ------------------------------------------------------------------
		// Entitlement Type
		//
		// Allows multiple entitlement sources.
		//
		// Examples:
		// - PAY_AS_YOU_GO
		// - SUBSCRIPTION
		// - PROMOTIONAL
		// ------------------------------------------------------------------
		if (!$table->hasColumn('type')) {
			$table->addColumn('type', 'string', [
				'length' => 64,
				'notnull' => true,
				'default' => EntitlementType::PAY_AS_YOU_GO->value,
			]);
		}

		// ------------------------------------------------------------------
		// Lookup optimisation
		// ------------------------------------------------------------------
		if (!$table->hasIndex('gp_ent_user_prod_type_idx')) {
			$table->addIndex(
				['user_id', 'product_code', 'type'],
				'gp_ent_user_prod_type_idx'
			);
		}

		return $schema;
	}
}
