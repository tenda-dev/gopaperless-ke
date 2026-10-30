<?php

declare(strict_types=1);

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/** Paid signing credits waiting for SecurySign's unit grant. */
class Version35000Date20261026000000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('gopaperless_signa_units')) {
			return null;
		}
		$table = $schema->createTable('gopaperless_signa_units');
		$table->addColumn('payment_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('user_key', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('units', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('grant_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('synced_at', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['payment_id']);
		return $schema;
	}
}
