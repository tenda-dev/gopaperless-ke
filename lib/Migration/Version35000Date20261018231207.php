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
 * Adds requester identity and claim-lifecycle fields to public workflows.
 *
 * The existing status column tracks the claim lifecycle without a separate
 * state column. Changes are additive and idempotent; existing rows are
 * preserved without data backfill.
 */
class Version35000Date20261018231207 extends SimpleMigrationStep {

	#[Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

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

		if (!$schema->hasTable('gopaperless_public_workflows')) {
			return null;
		}

		$table = $schema->getTable('gopaperless_public_workflows');

		// The email the anonymous requester entered. Display/matching/UX only —
		// never an authorization factor for claiming. Nullable for legacy rows.
		if (!$table->hasColumn('requester_email')) {
			$table->addColumn('requester_email', Types::STRING, [
				'notnull' => false,
				'length' => 320,
			]);
		}

		// Explicit captured intent that the requester will also be a signer.
		// Stored explicitly (not derived from email) so it survives OIDC where the
		// authenticated email may differ. Defaults false for legacy rows.
		if (!$table->hasColumn('requester_is_signer')) {
			$table->addColumn('requester_is_signer', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
		}

		// The authenticated uid that is claiming / has claimed this workflow.
		// Null until a claim starts.
		if (!$table->hasColumn('claimed_by_uid')) {
			$table->addColumn('claimed_by_uid', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}

		// Set when the claim transitions to 'claiming' (before the transfer), so an
		// interrupted claim is recoverable from the timestamp rather than guessed.
		if (!$table->hasColumn('claim_started_at')) {
			$table->addColumn('claim_started_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		// Set only after ownership transfer is verified and the workflow is 'claimed'.
		if (!$table->hasColumn('claimed_at')) {
			$table->addColumn('claimed_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		// Generic row-update stamp. Nullable so legacy rows need no backfill.
		if (!$table->hasColumn('updated_at')) {
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		return $schema;
	}

	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
