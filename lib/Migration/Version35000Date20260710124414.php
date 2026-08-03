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
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Adds a temporary sponsorship type to sign requests.
 *
 * While a file is in the draft state, sponsorship selections are stored
 * directly on the sign request so they can be edited without creating
 * reservations or persisted signer sponsorship records.
 *
 * Once the document transitions out of the draft state, the sponsorship
 * workflow reconciles the draft sponsorship selections into persisted
 * signer sponsorship records, which become the authoritative source for
 * sponsorship information.
 *
 * Existing sign requests default to SELF sponsorship to preserve
 * backwards compatibility.
 */
class Version35000Date20260710124414 extends SimpleMigrationStep {
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

		if ($schema->hasTable('libresign_sign_request')) {
			$table = $schema->getTable('libresign_sign_request');

			if (!$table->hasColumn('sponsorship_type')) {
				$table->addColumn(
					'sponsorship_type',
					Types::STRING,
					[
						'length' => 32,
						'default' => SponsorshipType::SELF->value,  // Preserve existing behaviour for legacy sign requests.
						'notnull' => true,
					]
				);
			}
		}

		return $schema;
	}
}
