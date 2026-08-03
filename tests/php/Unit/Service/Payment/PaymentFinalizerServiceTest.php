<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\ExtendedAccount\ExtendedAccountService;
use OCA\Libresign\Service\Payment\PaymentDateTimeHelper;
use OCA\Libresign\Service\Payment\PaymentFinalizerService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PaymentFinalizerServiceTest extends TestCase {
	private EntitlementService&MockObject $entitlementService;
	private PaymentMapper&MockObject $paymentMapper;
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;
	private PaymentDateTimeHelper&MockObject $dateTimeHelper;
	private ExtendedAccountMapper&MockObject $extendedAccountMapper;
	private IAppConfig&MockObject $appConfig;
	private ExtendedAccountService $extendedAccountService;
	private PaymentFinalizerService $service;

	/**
	 * Records the order of cross-mock interactions so tests can assert
	 * that the certificate renewal happens inside the transaction.
	 *
	 * @var string[]
	 */
	private array $calls = [];

	public function setUp(): void {
		parent::setUp();
		$this->entitlementService = $this->createMock(EntitlementService::class);
		$this->paymentMapper = $this->createMock(PaymentMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dateTimeHelper = $this->createMock(PaymentDateTimeHelper::class);
		$this->extendedAccountMapper = $this->createMock(ExtendedAccountMapper::class);

		$this->dateTimeHelper->method('now')->willReturn('2026-07-30T12:00:00+00:00');

		$this->db->method('beginTransaction')
			->willReturnCallback(function (): void {
				$this->calls[] = 'beginTransaction';
			});
		$this->db->method('commit')
			->willReturnCallback(function (): void {
				$this->calls[] = 'commit';
			});
		$this->db->method('rollBack')
			->willReturnCallback(function (): void {
				$this->calls[] = 'rollBack';
			});

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturn(365);

		$this->extendedAccountService = new ExtendedAccountService(
			$this->extendedAccountMapper,
			$this->createMock(SignRequestMapper::class),
			$this->logger,
			$this->appConfig,
		);

		$this->service = new PaymentFinalizerService(
			$this->entitlementService,
			$this->paymentMapper,
			$this->db,
			$this->logger,
			$this->dateTimeHelper,
			$this->extendedAccountService,
		);
	}

	public function testCertificatePurchaseRenewsCertificateInsideTransaction(): void {
		$payment = $this->newPayment(PaymentPurpose::CERTIFICATE_PURCHASE);

		$account = new ExtendedAccount();
		$account->setUserId('cert-user');
		$this->extendedAccountMapper->method('findByUserId')->willReturn($account);
		$this->extendedAccountMapper->expects($this->once())
			->method('update')
			->with($this->callback(
				static fn (ExtendedAccount $account): bool => $account->getUserId() === 'cert-user'
					&& $account->getPaidCertificate() === true
					&& $account->getValidUntilImmutable() !== null
			))
			->willReturnCallback(function (ExtendedAccount $account): ExtendedAccount {
				$this->calls[] = 'renewCertificate';
				return $account;
			});

		$this->entitlementService->expects($this->never())->method('create');
		$this->paymentMapper->expects($this->once())
			->method('update')
			->with($payment);

		$this->service->finalisePayment($payment);

		$this->assertSame(PaymentStatus::PAID, $payment->getPaymentStatus());
		$this->assertNotNull($payment->getPaidAt());

		$begin = array_search('beginTransaction', $this->calls, true);
		$renew = array_search('renewCertificate', $this->calls, true);
		$commit = array_search('commit', $this->calls, true);

		$this->assertNotFalse($begin);
		$this->assertNotFalse($renew);
		$this->assertNotFalse($commit);
		$this->assertLessThan($renew, $begin, 'Renewal must run after beginTransaction');
		$this->assertLessThan($commit, $renew, 'Renewal must run before commit');
		$this->assertNotContains('rollBack', $this->calls);
	}

	#[DataProvider('provideEntitlementPurposes')]
	public function testNonCertificatePurposesGrantEntitlementAndNeverRenewCertificate(
		PaymentPurpose $purpose,
	): void {
		$payment = $this->newPayment($purpose);
		$payment->setProductCode('SIGN_DOCUMENT');
		$payment->setProductUses(3);

		$this->extendedAccountMapper->expects($this->never())->method('update');
		$this->extendedAccountMapper->expects($this->never())->method('insert');
		$this->extendedAccountMapper->expects($this->never())->method('findByUserId');

		$this->entitlementService->expects($this->once())
			->method('create')
			->with('cert-user', 'SIGN_DOCUMENT', 3);

		$this->service->finalisePayment($payment);

		$this->assertSame(PaymentStatus::PAID, $payment->getPaymentStatus());
		$this->assertNotContains('renewCertificate', $this->calls);
		$this->assertNotContains('rollBack', $this->calls);
	}

	public static function provideEntitlementPurposes(): array {
		return [
			'sign request' => [PaymentPurpose::SIGN_REQUEST],
			'credit purchase' => [PaymentPurpose::CREDIT_PURCHASE],
		];
	}

	public function testAlreadyPaidPaymentIsNotProcessedAgain(): void {
		$payment = $this->newPayment(PaymentPurpose::CERTIFICATE_PURCHASE);
		$payment->setPaymentStatus(PaymentStatus::PAID);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->paymentMapper->expects($this->never())->method('update');
		$this->extendedAccountMapper->expects($this->never())->method('update');
		$this->entitlementService->expects($this->never())->method('create');

		$this->service->finalisePayment($payment);
	}

	public function testFailureRollsBackAndRethrows(): void {
		$payment = $this->newPayment(PaymentPurpose::SIGN_REQUEST);

		$this->extendedAccountMapper->expects($this->never())->method('update');
		$this->entitlementService->method('create')
			->willThrowException(new \RuntimeException('entitlement store down'));

		try {
			$this->service->finalisePayment($payment);
			$this->fail('Expected the entitlement failure to be rethrown');
		} catch (\RuntimeException $e) {
			$this->assertSame('entitlement store down', $e->getMessage());
		}

		$this->assertContains('rollBack', $this->calls);
		$this->assertNotContains('commit', $this->calls);
	}

	private function newPayment(PaymentPurpose $purpose): Payment {
		$payment = new Payment();
		$payment->setUserId('cert-user');
		$payment->setPaymentPurpose($purpose);
		$payment->setPaymentStatus(PaymentStatus::PENDING);
		return $payment;
	}
}
