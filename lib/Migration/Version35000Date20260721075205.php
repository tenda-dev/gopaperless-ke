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

class Version35000Date20260721075205 extends SimpleMigrationStep {

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

		if ($schema->hasTable('gopaperless_extended_accounts')) {
			return null;
		}

		// Extension table for application-specific account data.
		// Nextcloud's AccountManager cannot be extended directly, so
		// GoPaperless stores additional account state here.
		// The absence of a row is a valid business state, preserving
		// backwards compatibility for grandfathered(existing) accounts.
		$table = $schema->createTable('gopaperless_extended_accounts');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);

		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);

		// Indicates whether the user currently has certificate access.
		$table->addColumn('paid_certificate', Types::BOOLEAN, [
			'notnull' => true,
			'default' => false,
		]);

		// Set when a certificate purchase succeeds.
		// NULL indicates certificate access granted without payment.
		// Grandfathered users are represented by the absence of a row.
		$table->addColumn('cert_paid_at', Types::DATETIME, [
			'notnull' => false,
		]);

		// Business access window (annual by default, admin-configurable).
		// Deliberately independent of the certificate's X.509 notAfter.
		// NULL indicates no business expiry for this account.
		$table->addColumn('valid_until', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);

		/// One row per user. The absence of a row indicates a grandfathered
		// account that remains exempt from certificate payment.
		// The unique index enforces a 1:1 relationship.
		$table->addUniqueIndex(['user_id'], 'gp_ext_acc_uid_uniq');

		return $schema;
	}
}
