<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
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
 * Tuandikiane drafts: an agreement Tuandikiane rendered from a WhatsApp chat,
 * held by the service account until its sender reviews and sends it.
 *
 * One row per draft. The signer slots (key, phone, sender flag, sign request,
 * bound account) sit in a JSON column: they are only ever read and written
 * with their draft.
 */
class Version35000Date20261106120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('gopaperless_tuandikiane')) {
			return null;
		}

		$table = $schema->createTable('gopaperless_tuandikiane');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		// What Tuandikiane and the review code call the draft. Not the file's uuid.
		$table->addColumn('draft_uuid', Types::STRING, [
			'notnull' => true,
			'length' => 36,
		]);
		// Tuandikiane's own key for the agreement; a second call with it gets this draft back.
		$table->addColumn('reference', Types::STRING, [
			'notnull' => true,
			'length' => 128,
		]);
		// The libresign_file row. 0 only while the draft is being created.
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		// 'draft' until the sender sends it, then 'sent'. A sent draft never expires.
		$table->addColumn('status', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'draft',
		]);
		$table->addColumn('slots', Types::JSON, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('expires_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('sent_at', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['draft_uuid'], 'gp_tk_draft_uidx');
		$table->addUniqueIndex(['reference'], 'gp_tk_ref_uidx');
		$table->addIndex(['file_id'], 'gp_tk_file_idx');
		$table->addIndex(['status', 'expires_at'], 'gp_tk_expiry_idx');
		return $schema;
	}
}
