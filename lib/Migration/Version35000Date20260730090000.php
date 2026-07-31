<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\Service\Encryption\IdNumberEncryptionService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Encrypts existing plaintext id_number values at rest.
 *
 * The column is widened from 64 to 255 characters because the
 * versioned AES-256-GCM ciphertext (prefix + IV + tag + payload)
 * does not fit into 64 characters.
 */
class Version35000Date20260730090000 extends SimpleMigrationStep {
	private ?IDBConnection $connection = null;
	private ?IdNumberEncryptionService $idNumberEncryption = null;

	private function connection(): IDBConnection {
		if ($this->connection === null) {
			$this->connection = \OC::$server->get(IDBConnection::class);
		}
		return $this->connection;
	}

	private function idNumberEncryption(): IdNumberEncryptionService {
		if ($this->idNumberEncryption === null) {
			$this->idNumberEncryption = \OC::$server->get(IdNumberEncryptionService::class);
		}
		return $this->idNumberEncryption;
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

		if (!$schema->hasTable('gopaperless_extended_accounts')) {
			return null;
		}

		$table = $schema->getTable('gopaperless_extended_accounts');

		if ($table->hasColumn('id_number')) {
			$table->modifyColumn('id_number', [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_extended_accounts')) {
			return;
		}

		$select = $this->connection()->getQueryBuilder();
		$select->select('id', 'id_number')
			->from('gopaperless_extended_accounts')
			->where($select->expr()->isNotNull('id_number'))
			->andWhere(
				$select->expr()->neq(
					'id_number',
					$select->createNamedParameter(''),
				),
			);

		$result = $select->executeQuery();

		$encrypted = 0;

		while ($row = $result->fetch()) {
			$idNumber = (string)$row['id_number'];

			if ($this->idNumberEncryption()->isEncrypted($idNumber)) {
				continue;
			}

			$update = $this->connection()->getQueryBuilder();
			$update->update('gopaperless_extended_accounts')
				->set(
					'id_number',
					$update->createNamedParameter(
						$this->idNumberEncryption()->encrypt($idNumber),
					),
				)
				->where(
					$update->expr()->eq(
						'id',
						$update->createNamedParameter(
							$row['id'],
							Types::INTEGER,
						),
					),
				)
				->executeStatement();

			$encrypted++;
		}

		$result->closeCursor();

		if ($encrypted > 0) {
			$output->info(sprintf(
				'Encrypted %d existing id_number value(s) in gopaperless_extended_accounts.',
				$encrypted,
			));
		}
	}
}
