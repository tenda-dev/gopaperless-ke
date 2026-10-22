<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\PdcResaleService;
use OCA\Libresign\Service\SecurySignService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PdcResaleServiceTest extends TestCase {
	private SecurySignService&MockObject $signa;
	private ExtendedAccountMapper&MockObject $accounts;
	/** @var list<Payment> newest first */
	private array $payments = [];
	private bool $switchedOn = true;
	/** @var array<string, string> */
	private array $userValues = [];
	/** @var array<string, mixed> */
	private array $sessionValues = [];

	protected function setUp(): void {
		$this->signa = $this->createMock(SecurySignService::class);
		$this->signa->method('applies')->willReturn(true);
		$this->accounts = $this->createMock(ExtendedAccountMapper::class);
	}

	private function service(): PdcResaleService {
		$payments = $this->createMock(PaymentMapper::class);
		$payments->method('findRecentByUserId')->willReturnCallback(fn () => $this->payments);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false) => $key === 'pdc_resale_enabled' ? $this->switchedOn : $default,
		);
		$userConfig = $this->createMock(IConfig::class);
		$userConfig->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => $this->userValues[$key] ?? $default,
		);
		$userConfig->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value): void {
			$this->userValues[$key] = (string)$value;
		});
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key) => $this->sessionValues[$key] ?? null);
		$session->method('set')->willReturnCallback(function (string $key, $value): void {
			$this->sessionValues[$key] = $value;
		});
		return new PdcResaleService($this->signa, $this->accounts, $payments, $config, $userConfig, $session);
	}

	private static function payment(PaymentPurpose $purpose, PaymentStatus $status, string $attemptId): Payment {
		$payment = new Payment();
		$payment->setPaymentPurpose($purpose);
		$payment->setPaymentStatus($status);
		$payment->setPaymentAttemptId($attemptId);
		return $payment;
	}

	public function testItIsOffUntilSwitchedOn(): void {
		$this->switchedOn = false;
		self::assertFalse($this->service()->applies());

		$this->switchedOn = true;
		self::assertTrue($this->service()->applies());
	}

	public function testTheLatestCertificatePurchaseIsGrantedOnceUnderItsOwnKey(): void {
		$this->payments = [
			self::payment(PaymentPurpose::CREDIT_PURCHASE, PaymentStatus::PAID, 'credits-1'),
			self::payment(PaymentPurpose::CERTIFICATE_PURCHASE, PaymentStatus::PENDING, 'cert-pending'),
			self::payment(PaymentPurpose::CERTIFICATE_PURCHASE, PaymentStatus::PAID, 'cert-paid'),
		];
		$this->signa->expects(self::once())->method('grantPdc')->with('gp-pdc-cert-paid');

		$service = $this->service();
		$service->grant('alice');
		// A second trip to MIMI, or another device, finds it granted already.
		$service->grant('alice');

		self::assertSame('gp-pdc-cert-paid', $this->userValues[PdcResaleService::GRANTED]);
	}

	public function testThereIsNothingToGrantWithoutAPaidCertificate(): void {
		$this->payments = [self::payment(PaymentPurpose::CERTIFICATE_PURCHASE, PaymentStatus::PENDING, 'cert-pending')];
		$this->signa->expects(self::never())->method('grantPdc');

		$this->expectExceptionCode(409);
		$this->service()->grant('alice');
	}

	public function testAFailedGrantHoldsTheHandOffToMimiForAMinute(): void {
		$this->payments = [self::payment(PaymentPurpose::CERTIFICATE_PURCHASE, PaymentStatus::PAID, 'cert-paid')];
		$this->signa->method('grantPdc')->willThrowException(new \RuntimeException('no stock', 503));
		$service = $this->service();
		self::assertFalse($service->heldOff());

		try {
			$service->grant('alice');
			self::fail('a refused grant was taken as granted');
		} catch (\RuntimeException $e) {
			self::assertSame(503, $e->getCode());
		}

		self::assertTrue($service->heldOff());
		self::assertArrayNotHasKey(PdcResaleService::GRANTED, $this->userValues, 'it is tried again later');
	}

	public function testACertificateOnSecurySignOrAnOutageNeverPaywallsAnyone(): void {
		$unpaid = $this->createMock(ExtendedAccount::class);
		$unpaid->method('isCertificateValid')->willReturn(false);
		$this->accounts->method('findByUserId')->willReturn($unpaid);

		$ready = $this->createMock(SecurySignService::class);
		$ready->method('isReady')->willReturn(true);
		$this->signa = $ready;
		self::assertTrue($this->service()->certificateValid('alice'));

		$down = $this->createMock(SecurySignService::class);
		$down->method('isReady')->willThrowException(new \RuntimeException('SecurySign is unreachable.', 503));
		$this->signa = $down;
		self::assertTrue($this->service()->certificateValid('alice'));

		$notYet = $this->createMock(SecurySignService::class);
		$notYet->method('isReady')->willReturn(false);
		$this->signa = $notYet;
		self::assertFalse($this->service()->certificateValid('alice'), 'not set up and not paid: the checkout shows');
	}
}
