<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\Enum\SponsorshipType;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version35000Date20260706210008 extends SimpleMigrationStep
{

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_signer_sponsorships')) {

			$table = $schema->createTable('gopaperless_signer_sponsorships');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Workflow (LibreSign file/envelope)
			$table->addColumn('file_id', 'integer', [
				'notnull' => true,
			]);

			// LibreSign SignRequest reference
			// One sponsorship record per signer.
			$table->addColumn('sign_request_id', 'integer', [
				'notnull' => true,
			]);

			// Owner of the workflow
			$table->addColumn('sponsor_user_id', 'string', [
				'length' => 64,
				'notnull' => true,
			]);

			// SELF | REQUESTER
			$table->addColumn('sponsorship_type', 'string', [
				'length' => 32,
				'notnull' => true,
				'default' => SponsorshipType::SELF->value,
			]);

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);

			$table->addColumn('updated_at', 'datetime', [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);

			$table->addUniqueIndex(
				['sign_request_id'],
				'gp_spon_sr_uidx'
			);

			$table->addIndex(
				['file_id'],
				'gp_spon_file_idx'
			);

			$table->addIndex(
				['sponsor_user_id'],
				'gp_spon_user_idx'
			);
		}

		return $schema;
	}
}
