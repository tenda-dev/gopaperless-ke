<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Db;

use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Server;

/**
 * @group DB
 */
final class ExtendedAccountMapperTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private ExtendedAccountMapper $mapper;
	private IDBConnection $db;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(ExtendedAccountMapper::class);
		$this->db = Server::get(IDBConnection::class);
	}

	public function testFindByUserIdReturnsNullWhenNoRowExists(): void {
		$this->assertNull($this->mapper->findByUserId('mapper-test-absent-user'));
	}

	public function testFindByUserIdReturnsRowAfterInsert(): void {
		$account = $this->createAccountEntity('mapper-test-find-user');
		$this->mapper->insert($account);

		$found = $this->mapper->findByUserId('mapper-test-find-user');

		$this->assertInstanceOf(ExtendedAccount::class, $found);
		$this->assertSame('mapper-test-find-user', $found->getUserId());
		$this->assertFalse($found->getPaidCertificate());
		$this->assertNull($found->getValidUntilImmutable());
	}

	public function testDuplicateUserIdIsRejectedByUniqueIndex(): void {
		$this->mapper->insert($this->createAccountEntity('mapper-test-duplicate-user'));

		$this->expectException(Exception::class);
		$this->mapper->insert($this->createAccountEntity('mapper-test-duplicate-user'));
	}

	public function testFindByPaidCertificateFiltersAndOrdersByCreatedAt(): void {
		$paidOlder = $this->createAccountEntity('mapper-test-paid-older');
		$paidOlder->setPaidCertificate(true);
		$paidOlder->setCreatedAt(new \DateTime('2026-07-01 00:00:00', new \DateTimeZone('UTC')));
		$this->mapper->insert($paidOlder);

		$paidNewer = $this->createAccountEntity('mapper-test-paid-newer');
		$paidNewer->setPaidCertificate(true);
		$paidNewer->setCreatedAt(new \DateTime('2026-07-20 00:00:00', new \DateTimeZone('UTC')));
		$this->mapper->insert($paidNewer);

		$unpaid = $this->createAccountEntity('mapper-test-unpaid');
		$unpaid->setCreatedAt(new \DateTime('2026-07-10 00:00:00', new \DateTimeZone('UTC')));
		$this->mapper->insert($unpaid);

		$paidAccounts = $this->mapper->findByPaidCertificate(true);
		$paidUserIds = array_map(
			static fn (ExtendedAccount $account): string => $account->getUserId(),
			$paidAccounts,
		);

		$this->assertContains('mapper-test-paid-older', $paidUserIds);
		$this->assertContains('mapper-test-paid-newer', $paidUserIds);
		$this->assertNotContains('mapper-test-unpaid', $paidUserIds);

		$positionOlder = array_search('mapper-test-paid-older', $paidUserIds, true);
		$positionNewer = array_search('mapper-test-paid-newer', $paidUserIds, true);
		$this->assertLessThan(
			$positionNewer,
			$positionOlder,
			'Paid accounts must be ordered by created_at ASC',
		);
	}

	public function testFindByPaidCertificateReturnsUnpaidAccounts(): void {
		$this->mapper->insert($this->createAccountEntity('mapper-test-unpaid-filter'));

		$unpaidAccounts = $this->mapper->findByPaidCertificate(false);
		$unpaidUserIds = array_map(
			static fn (ExtendedAccount $account): string => $account->getUserId(),
			$unpaidAccounts,
		);

		$this->assertContains('mapper-test-unpaid-filter', $unpaidUserIds);
	}

	public function testInsertAndUpdateRoundTripPersistsAllFields(): void {
		$validUntil = '2027-01-01T00:00:00+00:00';

		$account = $this->createAccountEntity('mapper-test-roundtrip-user');
		$account->setIdNumber('12345678');
		$inserted = $this->mapper->insert($account);

		// Plaintext must be restored on the entity after the write.
		$this->assertSame('12345678', $inserted->getIdNumber());

		$found = $this->mapper->findByUserId('mapper-test-roundtrip-user');
		$this->assertSame('12345678', $found->getIdNumber());
		$this->assertFalse($found->getPaidCertificate());
		$this->assertNotNull($found->getCreatedAtImmutable());

		$found->setIdNumber('87654321');
		$found->markCertificatePaid();
		$found->setValidUntil($validUntil);
		$updated = $this->mapper->update($found);

		// Plaintext must be restored on the entity after the update.
		$this->assertSame('87654321', $updated->getIdNumber());

		$reloaded = $this->mapper->findByUserId('mapper-test-roundtrip-user');
		$this->assertSame('87654321', $reloaded->getIdNumber());
		$this->assertTrue($reloaded->getPaidCertificate());
		$this->assertNotNull($reloaded->getCertPaidAtImmutable());
		$this->assertEquals(
			new \DateTimeImmutable($validUntil),
			$reloaded->getValidUntilImmutable(),
		);
	}

	public function testIdNumberIsStoredEncryptedAtRest(): void {
		$account = $this->createAccountEntity('mapper-test-encrypted-user');
		$account->setIdNumber('99887766');
		$this->mapper->insert($account);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id_number')
			->from('gopaperless_extended_accounts')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter('mapper-test-encrypted-user')));
		$raw = $qb->executeQuery()->fetchOne();

		$this->assertIsString($raw);
		$this->assertNotSame('99887766', $raw);
		$this->assertStringStartsWith('v1:', $raw);
	}

	public function testNullIdNumberRoundTripStaysNull(): void {
		$this->mapper->insert($this->createAccountEntity('mapper-test-null-idnumber'));

		$found = $this->mapper->findByUserId('mapper-test-null-idnumber');

		$this->assertNull($found->getIdNumber());
	}

	private function createAccountEntity(string $userId): ExtendedAccount {
		$account = new ExtendedAccount();
		$account->setUserId($userId);
		return $account;
	}
}
