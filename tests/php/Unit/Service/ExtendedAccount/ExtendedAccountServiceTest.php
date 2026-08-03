<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\ExtendedAccount;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Service\ExtendedAccount\ExtendedAccountService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ExtendedAccountServiceTest extends TestCase {
	private ExtendedAccountMapper&MockObject $mapper;
	private SignRequestMapper&MockObject $signRequestMapper;
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private ExtendedAccountService $service;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(ExtendedAccountMapper::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->service = new ExtendedAccountService(
			$this->mapper,
			$this->signRequestMapper,
			$this->logger,
			$this->appConfig,
		);
	}

	public function testCreateThrowsWhenUserIdIsEmpty(): void {
		$this->mapper->expects($this->never())->method('findByUserId');
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('userId is required');
		$this->service->create('');
	}

	public function testCreateReturnsExistingAccount(): void {
		$existing = $this->newAccount('existing-user');

		$this->mapper->method('findByUserId')
			->with('existing-user')
			->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame($existing, $this->service->create('existing-user'));
	}

	public function testCreatePersistsNewUnpaidAccount(): void {
		$this->mapper->method('findByUserId')->willReturn(null);
		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(
				static fn (ExtendedAccount $account): bool => $account->getUserId() === 'new-user'
			))
			->willReturnArgument(0);

		$account = $this->service->create('new-user');

		$this->assertSame('new-user', $account->getUserId());
		$this->assertFalse($account->getPaidCertificate());
		$this->assertNull($account->getValidUntil());
	}

	public function testCreateReturnsExistingAccountOnUniqueConstraintRace(): void {
		$existing = $this->newAccount('raced-user');

		$this->mapper->method('findByUserId')
			->willReturnOnConsecutiveCalls(null, $existing);
		$this->mapper->method('insert')
			->willThrowException($this->uniqueConstraintViolation());

		$account = $this->service->create('raced-user');

		$this->assertSame($existing, $account);
	}

	public function testCreateRethrowsUnexpectedDatabaseErrors(): void {
		$this->mapper->method('findByUserId')->willReturn(null);
		$this->mapper->method('insert')
			->willThrowException(new \OCP\DB\Exception('connection lost'));

		$this->expectException(\OCP\DB\Exception::class);
		$this->expectExceptionMessage('connection lost');
		$this->service->create('failing-user');
	}

	public function testGetByUserIdDelegatesToMapper(): void {
		$account = $this->newAccount('lookup-user');
		$this->mapper->method('findByUserId')
			->with('lookup-user')
			->willReturn($account);

		$this->assertSame($account, $this->service->getByUserId('lookup-user'));
	}

	public function testGetOrCreateReturnsExistingAccount(): void {
		$existing = $this->newAccount('grandfathered-user');

		$this->mapper->method('findByUserId')->willReturn($existing);
		$this->signRequestMapper->expects($this->never())->method('hasSignedDocuments');
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(
			$existing,
			$this->service->getOrCreate('grandfathered-user', 'grandfathered@example.com'),
		);
	}

	public function testGetOrCreateGrantsCertificateAccessToLegacySigningUser(): void {
		$this->mapper->method('findByUserId')->willReturn(null);
		$this->signRequestMapper->method('hasSignedDocuments')
			->with('legacy-user', 'legacy@example.com')
			->willReturn(true);
		$this->appConfig->method('getValueInt')
			->with(Application::APP_ID, 'certificate_validity_days', 365)
			->willReturn(365);
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnArgument(0);

		$account = $this->service->getOrCreate('legacy-user', 'legacy@example.com');

		$this->assertTrue($account->getPaidCertificate());
		$this->assertNull($account->getCertPaidAt());
		$this->assertValidUntilApproximately(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+365 days'),
			$account,
		);
	}

	public function testGetOrCreateCreatesUnpaidAccountForNewUser(): void {
		$this->mapper->method('findByUserId')->willReturn(null);
		$this->signRequestMapper->method('hasSignedDocuments')->willReturn(false);
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnArgument(0);

		$account = $this->service->getOrCreate('brand-new-user', null);

		$this->assertFalse($account->getPaidCertificate());
		$this->assertNull($account->getValidUntil());
	}

	public function testSaveDelegatesToMapperUpdate(): void {
		$account = $this->newAccount('save-user');

		$this->mapper->expects($this->once())
			->method('update')
			->with($account)
			->willReturn($account);

		$this->assertSame($account, $this->service->save($account));
	}

	public function testRenewCertificateStartsWindowFromNowWhenNeverGranted(): void {
		$account = $this->newAccount('renew-user');
		$this->mapper->method('findByUserId')->willReturn($account);
		$this->appConfig->method('getValueInt')->willReturn(365);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$renewed = $this->service->renewCertificate('renew-user', 'renew@example.com');

		$this->assertTrue($renewed->getPaidCertificate());
		$this->assertNotNull($renewed->getCertPaidAtImmutable());
		$this->assertValidUntilApproximately(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+365 days'),
			$renewed,
		);
	}

	public function testRenewCertificateStacksOnTopOfRemainingValidity(): void {
		$currentExpiry = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+100 days');

		$account = $this->newAccount('stacking-user');
		$account->setPaidCertificate(true);
		$account->setValidUntil($currentExpiry->format(DATE_ATOM));

		$this->mapper->method('findByUserId')->willReturn($account);
		$this->appConfig->method('getValueInt')->willReturn(365);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$renewed = $this->service->renewCertificate('stacking-user');

		$this->assertValidUntilApproximately(
			$currentExpiry->modify('+365 days'),
			$renewed,
		);
	}

	public function testRenewCertificateStartsFromNowWhenExpired(): void {
		$account = $this->newAccount('expired-user');
		$account->setPaidCertificate(true);
		$account->setValidUntil(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-10 days')->format(DATE_ATOM),
		);

		$this->mapper->method('findByUserId')->willReturn($account);
		$this->appConfig->method('getValueInt')->willReturn(365);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$renewed = $this->service->renewCertificate('expired-user');

		$this->assertValidUntilApproximately(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+365 days'),
			$renewed,
		);
	}

	public function testRenewCertificateHonoursConfiguredValidityDays(): void {
		$account = $this->newAccount('config-user');
		$this->mapper->method('findByUserId')->willReturn($account);
		$this->appConfig->method('getValueInt')
			->with(Application::APP_ID, 'certificate_validity_days', 365)
			->willReturn(30);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$renewed = $this->service->renewCertificate('config-user');

		$this->assertValidUntilApproximately(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days'),
			$renewed,
		);
	}

	public function testRenewCertificateUsesSuppliedPaidAtTimestamp(): void {
		$paidAt = new \DateTimeImmutable('2026-07-20 09:00:00', new \DateTimeZone('UTC'));

		$account = $this->newAccount('paidat-user');
		$this->mapper->method('findByUserId')->willReturn($account);
		$this->appConfig->method('getValueInt')->willReturn(365);
		$this->mapper->method('update')->willReturnArgument(0);

		$renewed = $this->service->renewCertificate('paidat-user', null, $paidAt);

		$this->assertEquals($paidAt, $renewed->getCertPaidAtImmutable());
	}

	public function testIsGateEnabledDefaultsToFalse(): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'certificate_gate_enabled', false)
			->willReturn(false);

		$this->assertFalse($this->service->isGateEnabled());
	}

	public function testIsGateEnabledReadsConfiguredValue(): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'certificate_gate_enabled', false)
			->willReturn(true);

		$this->assertTrue($this->service->isGateEnabled());
	}

	public function testIsCertificateValidForAccountIsAlwaysTrueWhenGateDisabled(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);

		$this->assertTrue(
			$this->service->isCertificateValidForAccount($this->newAccount('unpaid-user')),
		);
	}

	public function testIsCertificateValidForAccountUsesAccountStateWhenGateEnabled(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);

		$this->assertFalse(
			$this->service->isCertificateValidForAccount($this->newAccount('unpaid-user')),
		);

		$paid = $this->newAccount('paid-user');
		$paid->setPaidCertificate(true);
		$this->assertTrue(
			$this->service->isCertificateValidForAccount($paid),
		);

		$expired = $this->newAccount('expired-user');
		$expired->setPaidCertificate(true);
		$expired->setValidUntil(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 day')->format(DATE_ATOM),
		);
		$this->assertFalse(
			$this->service->isCertificateValidForAccount($expired),
		);
	}

	public function testGetAccountsWithPaidCertificatesDelegatesToMapper(): void {
		$accounts = [$this->newAccount('paid-one'), $this->newAccount('paid-two')];

		$this->mapper->expects($this->once())
			->method('findByPaidCertificate')
			->with(true)
			->willReturn($accounts);

		$this->assertSame($accounts, $this->service->getAccountsWithPaidCertificates(true));
	}

	private function newAccount(string $userId): ExtendedAccount {
		$account = new ExtendedAccount();
		$account->setUserId($userId);
		return $account;
	}

	private function uniqueConstraintViolation(): \OCP\DB\Exception {
		return new class('duplicate key') extends \OCP\DB\Exception {
			#[\Override]
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	private function assertValidUntilApproximately(
		\DateTimeImmutable $expected,
		ExtendedAccount $account,
	): void {
		$validUntil = $account->getValidUntilImmutable();

		$this->assertInstanceOf(\DateTimeImmutable::class, $validUntil);
		$this->assertEqualsWithDelta(
			$expected->getTimestamp(),
			$validUntil->getTimestamp(),
			5,
		);
	}
}
