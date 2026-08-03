<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Libresign\Migration\Version35000Date20260721075205;
use OCA\Libresign\Migration\Version35000Date20260728053647;
use OCA\Libresign\Migration\Version35000Date20260730090000;
use OCA\Libresign\Service\Encryption\IdNumberEncryptionService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;

final class ExtendedAccountMigrationsTest extends TestCase {
	private IOutput&MockObject $output;

	public function setUp(): void {
		parent::setUp();
		$this->output = $this->createMock(IOutput::class);
	}

	public function testCreateTableMigrationCreatesTableWithUniqueIndex(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(false);

		$table = $this->createMock(Table::class);

		$columns = [];
		$table->expects($this->exactly(6))
			->method('addColumn')
			->willReturnCallback(
				static function (string $name, string $type, array $options) use (&$columns, $table): Table {
					$columns[$name] = ['type' => $type, 'options' => $options];
					return $table;
				}
			);
		$table->expects($this->once())
			->method('setPrimaryKey')
			->with(['id']);
		$table->expects($this->once())
			->method('addUniqueIndex')
			->with(['user_id'], 'gp_ext_acc_uid_uniq');

		$schema->expects($this->once())
			->method('createTable')
			->with('gopaperless_extended_accounts')
			->willReturn($table);

		$result = (new Version35000Date20260721075205())->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertSame($schema, $result);
		$this->assertSame(
			['id', 'created_at', 'user_id', 'paid_certificate', 'cert_paid_at', 'valid_until'],
			array_keys($columns),
		);
		$this->assertSame(Types::BIGINT, $columns['id']['type']);
		$this->assertTrue($columns['id']['options']['autoincrement']);
		$this->assertSame(Types::DATETIME, $columns['created_at']['type']);
		$this->assertSame(Types::STRING, $columns['user_id']['type']);
		$this->assertSame(64, $columns['user_id']['options']['length']);
		$this->assertSame(Types::BOOLEAN, $columns['paid_certificate']['type']);
		$this->assertFalse($columns['paid_certificate']['options']['default']);
		$this->assertSame(Types::DATETIME, $columns['cert_paid_at']['type']);
		$this->assertFalse($columns['cert_paid_at']['options']['notnull']);
		$this->assertSame(Types::DATETIME, $columns['valid_until']['type']);
		$this->assertFalse($columns['valid_until']['options']['notnull']);
	}

	public function testCreateTableMigrationIsIdempotent(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$result = (new Version35000Date20260721075205())->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertNull($result);
	}

	public function testIdNumberMigrationAddsColumnWhenMissing(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')
			->with('id_number')
			->willReturn(false);
		$table->expects($this->once())
			->method('addColumn')
			->with('id_number', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);

		$schema = $this->schemaWithTable($table);

		$result = (new Version35000Date20260728053647())->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertSame($schema, $result);
	}

	public function testIdNumberMigrationIsIdempotent(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')
			->with('id_number')
			->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->schemaWithTable($table);

		(new Version35000Date20260728053647())->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);
	}

	public function testIdNumberMigrationSkipsWhenTableMissing(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = (new Version35000Date20260728053647())->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertNull($result);
	}

	public function testEncryptionMigrationWidensIdNumberColumn(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')
			->with('id_number')
			->willReturn(true);
		$table->expects($this->once())
			->method('modifyColumn')
			->with('id_number', [
				'notnull' => false,
				'length' => 255,
			]);

		$schema = $this->schemaWithTable($table);
		$migration = $this->encryptionMigration();

		$result = $migration->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertSame($schema, $result);
	}

	public function testEncryptionMigrationSkipsSchemaChangeWhenTableMissing(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$migration = $this->encryptionMigration();

		$result = $migration->changeSchema(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);

		$this->assertNull($result);
	}

	public function testEncryptionMigrationEncryptsExistingPlaintextRows(): void {
		$connection = $this->connectionReturningRows(
			[
				['id' => 1, 'id_number' => '12345678'],
				['id' => 2, 'id_number' => 'v1:alreadyencrypted'],
			],
			expectedUpdates: 1,
		);

		$this->output->expects($this->once())
			->method('info')
			->with('Encrypted 1 existing id_number value(s) in gopaperless_extended_accounts.');

		$migration = new Version35000Date20260730090000($connection, $this->realEncryption());
		$migration->postSchemaChange(
			$this->output,
			fn (): ISchemaWrapper => $this->schemaWithTable($this->createMock(Table::class)),
			[],
		);
	}

	public function testEncryptionMigrationDoesNothingWhenAllRowsEncrypted(): void {
		$connection = $this->connectionReturningRows(
			[
				['id' => 1, 'id_number' => 'v1:alreadyencrypted'],
			],
			expectedUpdates: 0,
		);

		$this->output->expects($this->never())->method('info');

		$migration = new Version35000Date20260730090000($connection, $this->realEncryption());
		$migration->postSchemaChange(
			$this->output,
			fn (): ISchemaWrapper => $this->schemaWithTable($this->createMock(Table::class)),
			[],
		);
	}

	public function testEncryptionMigrationSkipsPostChangeWhenTableMissing(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('getQueryBuilder');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(false);

		$migration = new Version35000Date20260730090000(
			$connection,
			$this->realEncryption(),
		);
		$migration->postSchemaChange(
			$this->output,
			static fn (): ISchemaWrapper => $schema,
			[],
		);
	}

	private function encryptionMigration(): Version35000Date20260730090000 {
		return new Version35000Date20260730090000(
			$this->createMock(IDBConnection::class),
			$this->realEncryption(),
		);
	}

	/**
	 * IdNumberEncryptionService is final, so drive the migrations with a
	 * real instance backed by deterministic key material.
	 */
	private function realEncryption(): IdNumberEncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')
			->with('secret', '')
			->willReturn('unit-test-secret');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new IdNumberEncryptionService($config, $appConfig);
	}

	private function schemaWithTable(Table&MockObject $table): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->with('gopaperless_extended_accounts')
			->willReturn(true);
		$schema->method('getTable')
			->with('gopaperless_extended_accounts')
			->willReturn($table);
		return $schema;
	}

	/**
	 * Connection stub that walks the select/update query builder chain used by
	 * Version35000Date20260730090000::postSchemaChange().
	 */
	private function connectionReturningRows(array $rows, int $expectedUpdates): IDBConnection&MockObject {
		$expressionBuilder = $this->createMock(IExpressionBuilder::class);
		$expressionBuilder->method('isNotNull')->willReturn('id_number IS NOT NULL');
		$expressionBuilder->method('neq')->willReturn('id_number <> :dcValue');
		$expressionBuilder->method('eq')->willReturn('id = :dcValue');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')
			->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->expects($this->once())->method('closeCursor');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('expr')->willReturn($expressionBuilder);
		$selectQb->method('createNamedParameter')->willReturn(':dcValue');
		$selectQb->method('executeQuery')->willReturn($result);

		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('expr')->willReturn($expressionBuilder);
		$updateQb->method('createNamedParameter')->willReturn(':dcValue');
		$updateQb->expects($this->exactly($expectedUpdates))
			->method('executeStatement')
			->willReturn(1);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')
			->willReturnOnConsecutiveCalls($selectQb, $updateQb);

		return $connection;
	}
}
