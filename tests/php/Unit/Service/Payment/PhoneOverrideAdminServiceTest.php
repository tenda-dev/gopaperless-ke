<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\MnoRoutingRegistry;
use OCA\Libresign\Service\Payment\PaymentDateTimeHelper;
use OCA\Libresign\Service\Payment\PhoneMnoResolver;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;

final class PhoneOverrideAdminServiceTest extends TestCase {
	private PhoneMnoOverrideMapper&MockObject $mapper;
	private PaymentDateTimeHelper&MockObject $dateTimeHelper;
	private PhoneOverrideAdminService $service;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(PhoneMnoOverrideMapper::class);
		$this->dateTimeHelper = $this->createMock(PaymentDateTimeHelper::class);
		$this->dateTimeHelper->method('nowImmutable')
			->willReturn(new \DateTimeImmutable('2026-08-29T12:00:00+00:00'));
		$this->service = new PhoneOverrideAdminService($this->mapper, $this->dateTimeHelper);
	}

	public function testCreateNormalizesAndStoresSafaricomActive(): void {
		$this->mapper->method('findByPhone')->willReturn(null);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (PhoneMnoOverride $e) use (&$captured) {
				$captured = $e;
				return $e;
			});

		$result = $this->service->createSafaricomException('+254 712 345 678', 'admin');

		self::assertSame('created', $result['status']);
		self::assertSame('+254712345678', $captured->getPhoneE164Digits());
		self::assertSame('safaricom', $captured->getMno());
		self::assertTrue($captured->getActive());
		self::assertSame('admin', $captured->getCreatedBy());
		// The stored key MUST equal what the resolver will look up.
		self::assertSame(
			PhoneMnoResolver::normalizePhoneKey('+254 712 345 678'),
			$captured->getPhoneE164Digits(),
		);
	}

	public function testStoredMnoRoutesToDarajaViaRealRegistry(): void {
		// Proves mno=safaricom -> existing routing -> Daraja, with no rail logic here.
		$route = (new MnoRoutingRegistry())->route(
			PaymentCapability::MOBILE_MONEY,
			'kenya',
			'KE',
			PhoneOverrideAdminService::MNO_SAFARICOM,
			ResolutionConfidence::HIGH,
		);
		self::assertSame(PaymentProvider::DARAJA, $route->preferredProvider);
	}

	public function testCreateRejectsEmpty(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createSafaricomException('   ', 'admin');
	}

	public function testCreateRejectsImplausiblyShort(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createSafaricomException('+12', 'admin');
	}

	public function testCreateDuplicateActiveIsReported(): void {
		$existing = $this->makeOverride(5, '+254712345678', true);
		$this->mapper->method('findByPhone')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->createSafaricomException('+254712345678', 'admin');

		self::assertSame('duplicate', $result['status']);
		self::assertSame(5, $result['override']['id']);
	}

	public function testCreateReactivatesInactiveDuplicate(): void {
		$existing = $this->makeOverride(7, '+254712345678', false);
		$this->mapper->method('findByPhone')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->createSafaricomException('+254712345678', 'admin');

		self::assertSame('reactivated', $result['status']);
		self::assertTrue($existing->getActive());
	}

	public function testUpdateChangesActiveState(): void {
		$existing = $this->makeOverride(9, '+254712345678', true);
		$this->mapper->method('findById')->with(9)->willReturn($existing);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->update(9, null, false);

		self::assertSame('updated', $result['status']);
		self::assertFalse($result['override']['active']);
		self::assertFalse($existing->getActive());
	}

	public function testUpdateChangesPhoneNumber(): void {
		$existing = $this->makeOverride(3, '+254712345678', true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->method('findByPhone')->willReturn(null);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->update(3, '+254 799 000 111', null);

		self::assertSame('updated', $result['status']);
		self::assertSame('+254799000111', $existing->getPhoneE164Digits());
	}

	public function testUpdateToPhoneUsedByAnotherRowIsDuplicate(): void {
		$existing = $this->makeOverride(3, '+254712345678', true);
		$other = $this->makeOverride(4, '+254799000111', true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->method('findByPhone')->willReturn($other);
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->update(3, '+254799000111', null);

		self::assertSame('duplicate', $result['status']);
		self::assertSame(4, $result['override']['id']);
	}

	public function testUpdateUnknownIdThrows(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(123, null, true);
	}

	public function testDeleteRemovesEntity(): void {
		$existing = $this->makeOverride(9, '+254712345678', true);
		$this->mapper->method('findById')->with(9)->willReturn($existing);
		$this->mapper->expects($this->once())->method('delete')->with($existing);

		$this->service->delete(9);
	}

	public function testDeleteUnknownIdThrows(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(404);
	}

	private function makeOverride(int $id, string $phone, bool $active): PhoneMnoOverride {
		$o = new PhoneMnoOverride();
		$o->setId($id);
		$o->setPhoneE164Digits($phone);
		$o->setMno('safaricom');
		$o->setActive($active);
		$o->setCreatedAt(new \DateTime('2026-08-01T00:00:00+00:00'));
		return $o;
	}
}
