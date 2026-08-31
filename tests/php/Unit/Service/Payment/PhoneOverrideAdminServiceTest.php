<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Service\Payment\MnoRoutingRegistry;
use OCA\Libresign\Service\Payment\MnoSuggestionValidatorService;
use OCA\Libresign\Service\Payment\PaymentDateTimeHelper;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Service\Payment\PhoneResolutionService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Uses the REAL MnoRoutingRegistry so supportsMno()/supportsRoute() validation
 * is exercised authentically; phone parsing and persistence are mocked.
 */
final class PhoneOverrideAdminServiceTest extends TestCase {
	private PhoneMnoOverrideMapper&MockObject $mapper;
	private PaymentDateTimeHelper&MockObject $dateTimeHelper;
	private PhoneResolutionService&MockObject $phoneResolution;
	private MnoSuggestionValidatorService&MockObject $mnoSuggestionValidator;
	private LoggerInterface&MockObject $logger;
	private PhoneOverrideAdminService $service;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(PhoneMnoOverrideMapper::class);
		$this->dateTimeHelper = $this->createMock(PaymentDateTimeHelper::class);
		$this->phoneResolution = $this->createMock(PhoneResolutionService::class);
		$this->mnoSuggestionValidator = $this->createMock(MnoSuggestionValidatorService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->dateTimeHelper->method('nowImmutable')
			->willReturn(new \DateTimeImmutable('2026-08-29T12:00:00+00:00'));
		// Any input normalizes to this KE number/region for these unit tests.
		$this->phoneResolution->method('parseLenient')->willReturn([
			'e164' => '+254712345678',
			'region' => 'KE',
			'national' => '712345678',
			'carrierHint' => null,
		]);

		$this->service = new PhoneOverrideAdminService(
			$this->mapper,
			$this->dateTimeHelper,
			new MnoRoutingRegistry(),
			$this->phoneResolution,
			$this->mnoSuggestionValidator,
			$this->logger,
		);
	}

	public function testCreateNormalizesAndStoresMnoAndProvider(): void {
		$this->mapper->method('findByPhone')->willReturn(null);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (PhoneMnoOverride $e) use (&$captured) {
				$captured = $e;
				return $e;
			});

		$result = $this->service->createException('0712345678', 'safaricom', 'daraja', 'admin');

		self::assertSame('created', $result['status']);
		self::assertSame('+254712345678', $captured->getPhoneE164Digits());
		self::assertSame('safaricom', $captured->getMno());
		self::assertSame('daraja', $captured->getProvider());
		self::assertTrue($captured->getActive());
		self::assertSame('admin', $captured->getCreatedBy());
	}

	public function testCreateValidatesSupportedMno(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createException('+254712345678', 'not-a-network', 'dpo', 'admin');
	}

	public function testCreateValidatesSupportedProvider(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createException('+254712345678', 'safaricom', 'not-a-provider', 'admin');
	}

	public function testCreateRejectsUnsupportedCombination(): void {
		// Airtel cannot be routed through Daraja.
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createException('+254712345678', 'airtel', 'daraja', 'admin');
	}

	public function testCreateAcceptsCrossProviderCombination(): void {
		// Safaricom + DPO is a supported cross-provider combination.
		$this->mapper->method('findByPhone')->willReturn(null);
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->createException('+254712345678', 'safaricom', 'dpo', 'admin');

		self::assertSame('created', $result['status']);
	}

	public function testCreateDuplicateActiveIsReported(): void {
		$existing = $this->makeOverride(5, 'safaricom', 'daraja', true);
		$this->mapper->method('findByPhone')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->createException('+254712345678', 'safaricom', 'daraja', 'admin');

		self::assertSame('duplicate', $result['status']);
		self::assertSame(5, $result['override']['id']);
	}

	public function testCreateReactivatesInactiveDuplicate(): void {
		$existing = $this->makeOverride(7, 'airtel', 'dpo', false);
		$this->mapper->method('findByPhone')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->createException('+254712345678', 'safaricom', 'daraja', 'admin');

		self::assertSame('reactivated', $result['status']);
		self::assertTrue($existing->getActive());
		self::assertSame('safaricom', $existing->getMno());
		self::assertSame('daraja', $existing->getProvider());
	}

	public function testUpdateChangesProvider(): void {
		$existing = $this->makeOverride(3, 'safaricom', 'daraja', true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->update(3, null, null, 'dpo', null);

		self::assertSame('updated', $result['status']);
		self::assertSame('dpo', $existing->getProvider());
	}

	public function testUpdateRejectsUnsupportedCombinationOnProviderChange(): void {
		$existing = $this->makeOverride(3, 'airtel', 'dpo', true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(3, null, null, 'daraja', null);
	}

	public function testUpdateActiveOnlySkipsCombinationCheck(): void {
		// An (already-persisted) airtel+daraja row can still be DEACTIVATED.
		$existing = $this->makeOverride(3, 'airtel', 'daraja', true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn (PhoneMnoOverride $e) => $e);

		$result = $this->service->update(3, null, null, null, false);

		self::assertSame('updated', $result['status']);
		self::assertFalse($existing->getActive());
	}

	public function testUpdateUnknownIdThrows(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(123, null, null, null, true);
	}

	public function testDeleteRemovesEntity(): void {
		$existing = $this->makeOverride(9, 'safaricom', 'daraja', true);
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

	private function makeOverride(int $id, string $mno, string $provider, bool $active): PhoneMnoOverride {
		$o = new PhoneMnoOverride();
		$o->setId($id);
		$o->setPhoneE164Digits('+254712345678');
		$o->setMno($mno);
		$o->setProvider($provider);
		$o->setActive($active);
		$o->setCreatedAt(new \DateTime('2026-08-01T00:00:00+00:00'));
		return $o;
	}
}
