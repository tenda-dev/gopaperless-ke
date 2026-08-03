<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Db;

use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExtendedAccountWithForcedDateForTest extends ExtendedAccount {
	public function forceValidUntilForTest(string $value): void {
		$this->validUntil = $value;
	}
}

final class ExtendedAccountTest extends TestCase {
	private ExtendedAccount $account;

	public function setUp(): void {
		parent::setUp();
		$this->account = new ExtendedAccount();
	}

	public function testDefaultFieldValues(): void {
		$this->assertSame('', $this->account->getUserId());
		$this->assertNull($this->account->getIdNumber());
		$this->assertFalse($this->account->getPaidCertificate());
		$this->assertNull($this->account->getCertPaidAt());
		$this->assertNull($this->account->getValidUntil());
		$this->assertNull($this->account->getCertPaidAtImmutable());
		$this->assertNull($this->account->getValidUntilImmutable());
	}

	public function testCreatedAtIsInitializedOnConstruction(): void {
		$createdAt = $this->account->getCreatedAtImmutable();

		$this->assertInstanceOf(\DateTimeImmutable::class, $createdAt);
		$this->assertEqualsWithDelta(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp(),
			$createdAt->getTimestamp(),
			5,
		);
	}

	#[DataProvider('provideCertificateValidityCases')]
	public function testIsCertificateValid(bool $paid, ?string $validUntilModifier, bool $expected): void {
		$this->account->setPaidCertificate($paid);
		if ($validUntilModifier !== null) {
			$this->account->setValidUntil(
				(new \DateTimeImmutable($validUntilModifier, new \DateTimeZone('UTC')))->format(DATE_ATOM),
			);
		}

		$this->assertSame($expected, $this->account->isCertificateValid());
		$this->assertSame(!$expected, $this->account->isCertificateRequired());
	}

	public static function provideCertificateValidityCases(): array {
		return [
			'unpaid without validity' => [false, null, false],
			'unpaid with future validity' => [false, '+1 day', false],
			'paid without business expiry' => [true, null, true],
			'paid with future validity' => [true, '+1 day', true],
			'paid with past validity' => [true, '-1 day', false],
		];
	}

	public function testIsCertificateValidAcceptsStringValidity(): void {
		$this->account->setPaidCertificate(true);
		$this->account->setValidUntil(
			(new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(DATE_ATOM),
		);

		$this->assertTrue($this->account->isCertificateValid());
	}

	public function testMarkCertificatePaidDefaultsToCurrentTime(): void {
		$result = $this->account->markCertificatePaid();

		$this->assertSame($this->account, $result);
		$this->assertTrue($this->account->getPaidCertificate());

		$paidAt = $this->account->getCertPaidAtImmutable();
		$this->assertInstanceOf(\DateTimeImmutable::class, $paidAt);
		$this->assertEqualsWithDelta(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp(),
			$paidAt->getTimestamp(),
			5,
		);
	}

	public function testMarkCertificatePaidAcceptsExplicitTimestamp(): void {
		$paidAt = new \DateTime('2026-07-01 10:00:00', new \DateTimeZone('UTC'));

		$this->account->markCertificatePaid($paidAt);

		$this->assertTrue($this->account->getPaidCertificate());
		$this->assertEquals(
			\DateTimeImmutable::createFromMutable($paidAt),
			$this->account->getCertPaidAtImmutable(),
		);
	}

	public function testMarkCertificatePaidAcceptsStringTimestamp(): void {
		$this->account->markCertificatePaid('2026-07-01T10:00:00+00:00');

		$this->assertEquals(
			new \DateTimeImmutable('2026-07-01T10:00:00+00:00'),
			$this->account->getCertPaidAtImmutable(),
		);
	}

	public function testMarkCertificatePaidDoesNotTouchValidityWindow(): void {
		$this->account->markCertificatePaid();

		$this->assertNull($this->account->getValidUntil());
	}

	public function testGrantCertificateAccessWithoutPayment(): void {
		$validUntil = (new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')))->format(DATE_ATOM);

		$result = $this->account->grantCertificateAccess($validUntil);

		$this->assertSame($this->account, $result);
		$this->assertTrue($this->account->getPaidCertificate());
		$this->assertNull($this->account->getCertPaidAt());
		$this->assertEquals(
			new \DateTimeImmutable($validUntil),
			$this->account->getValidUntilImmutable(),
		);
		$this->assertTrue($this->account->isCertificateValid());
	}

	public function testGrantCertificateAccessClearsPreviousPaymentTimestamp(): void {
		$this->account->markCertificatePaid();

		$this->account->grantCertificateAccess();

		$this->assertNull($this->account->getCertPaidAt());
		$this->assertNull($this->account->getValidUntil());
	}

	public function testRenewCertificateSetsPaymentAndValidity(): void {
		$validUntil = (new \DateTimeImmutable('+365 days', new \DateTimeZone('UTC')))->format(DATE_ATOM);
		$paidAt = '2026-07-15T08:30:00+00:00';

		$result = $this->account->renewCertificate($validUntil, $paidAt);

		$this->assertSame($this->account, $result);
		$this->assertTrue($this->account->getPaidCertificate());
		$this->assertEquals(
			new \DateTimeImmutable($paidAt),
			$this->account->getCertPaidAtImmutable(),
		);
		$this->assertEquals(
			new \DateTimeImmutable($validUntil),
			$this->account->getValidUntilImmutable(),
		);
	}

	public function testRenewCertificateDefaultsPaidAtToCurrentTime(): void {
		$this->account->renewCertificate(
			(new \DateTimeImmutable('+365 days', new \DateTimeZone('UTC')))->format(DATE_ATOM),
		);

		$paidAt = $this->account->getCertPaidAtImmutable();
		$this->assertInstanceOf(\DateTimeImmutable::class, $paidAt);
		$this->assertEqualsWithDelta(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp(),
			$paidAt->getTimestamp(),
			5,
		);
	}

	public function testImmutableDateHelpersConvertPersistedStrings(): void {
		$this->account->setCertPaidAt('2026-07-01T10:00:00+00:00');
		$this->account->setValidUntil('2027-07-01T10:00:00+00:00');

		$this->assertEquals(
			new \DateTimeImmutable('2026-07-01T10:00:00+00:00'),
			$this->account->getCertPaidAtImmutable(),
		);
		$this->assertEquals(
			new \DateTimeImmutable('2027-07-01T10:00:00+00:00'),
			$this->account->getValidUntilImmutable(),
		);
	}

	public function testImmutableDateHelpersReturnNullForInvalidStrings(): void {
		// The Entity setter rejects unparseable dates, so force the raw
		// persisted value the way a legacy/corrupt row would surface it.
		$account = new ExtendedAccountWithForcedDateForTest();
		$account->forceValidUntilForTest('not-a-date');

		$this->assertNull($account->getValidUntilImmutable());
	}

	public function testImmutableDateHelpersWrapMutableInstances(): void {
		$mutable = new \DateTime('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
		$this->account->setValidUntil($mutable);

		$immutable = $this->account->getValidUntilImmutable();

		$this->assertInstanceOf(\DateTimeImmutable::class, $immutable);
		$this->assertEquals($mutable->getTimestamp(), $immutable->getTimestamp());
	}
}
