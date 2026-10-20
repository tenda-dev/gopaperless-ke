<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Creates the gopaperless_public_workflows table for anonymous public document-preparation workflows.
 *
 * Stores the capability hash, temporary owner, document reference, and
 * workflow expiry. The raw capability token is never persisted.
 */
class Version35000Date20261016121401 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('gopaperless_public_workflows')) {
			return null;
		}

		$table = $schema->createTable('gopaperless_public_workflows');

		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
		]);

		// SHA-256 hex of the capability token (64 chars). The raw token is never
		// stored; this hash is the only server-side record of the capability.
		$table->addColumn('token_hash', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);

		// The server-created temporary Nextcloud user that owns this workflow's
		// document. One stable owner per workflow.
		$table->addColumn('owner_uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);

		// 'active' | 'revoked'. Expiry is time-based via expires_at.
		$table->addColumn('status', Types::STRING, [
			'notnull' => true,
			'length' => 32,
			'default' => 'active',
		]);

		// LibreSign file UUID of the single uploaded document (reference only;
		// ownership is authoritative on the LibreSign file record). Null until
		// the one document is uploaded.
		$table->addColumn('file_uuid', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);

		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->addColumn('expires_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token_hash'], 'gp_pw_token_uidx');
		$table->addIndex(['owner_uid'], 'gp_pw_owner_idx');
		$table->addIndex(['expires_at'], 'gp_pw_expires_idx');

		return $schema;
	}
}
