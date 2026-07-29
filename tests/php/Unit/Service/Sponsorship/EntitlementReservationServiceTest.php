<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Sponsorship;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class EntitlementReservationServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementReservationMapper&MockObject $reservationMapper;
	private LoggerInterface&MockObject $logger;
	private EntitlementReservationService $service;

	public function setUp(): void {
		parent::setUp();
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->reservationMapper = $this->createMock(EntitlementReservationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new EntitlementReservationService(
			$this->entitlementMapper,
			$this->reservationMapper,
			$this->logger,
		);
	}

	private function createEntitlement(int $id, int $remainingUses, int $reservedUses = 0): Entitlement {
		$entitlement = new Entitlement();
		$entitlement->setId($id);
		$entitlement->setRemainingUses($remainingUses);
		$entitlement->setReservedUses($reservedUses);
		return $entitlement;
	}

	public function testReserveForWorkflowAcrossMultipleEntitlements(): void {
		$entitlementA = $this->createEntitlement(1, 5, 0);
		$entitlementB = $this->createEntitlement(2, 10, 0);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->with('requester', 'SIGN_DOCUMENT')
			->willReturn([$entitlementA, $entitlementB]);

		$this->reservationMapper
			->expects($this->exactly(2))
			->method('insert');
		$this->entitlementMapper
			->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(function (Entitlement $entitlement) {
				if ($entitlement->getId() === 1) {
					$this->assertSame(5, $entitlement->getReservedUses());
				} else {
					$this->assertSame(2, $entitlement->getReservedUses());
				}
			});

		$this->service->reserveForWorkflow(
			'requester',
			'SIGN_DOCUMENT',
			100,
			1,
			7,
		);
	}

	public function testReserveForWorkflowThrowsWhenCapacityInsufficient(): void {
		$entitlement = $this->createEntitlement(1, 1, 0);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->with('requester', 'SIGN_DOCUMENT')
			->willReturn([$entitlement]);

		$this->reservationMapper
			->expects($this->once())
			->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Insufficient entitlement capacity');

		$this->service->reserveForWorkflow(
			'requester',
			'SIGN_DOCUMENT',
			100,
			1,
			2,
		);
	}

	public function testGetReservedUsesSumsReservedUses(): void {
		$entitlementA = $this->createEntitlement(1, 10, 3);
		$entitlementB = $this->createEntitlement(2, 10, 2);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->with('requester', 'SIGN_DOCUMENT')
			->willReturn([$entitlementA, $entitlementB]);

		$this->assertSame(5, $this->service->getReservedUses('requester', 'SIGN_DOCUMENT'));
	}

	public function testApplyReservationReleaseDecreasesReservedUsesAndSetsReleasedAt(): void {
		$entitlement = $this->createEntitlement(1, 10, 5);
		$reservation = new \OCA\Libresign\Db\EntitlementReservation();
		$reservation->setQuantity(5);

		$this->service->applyReservationRelease($reservation, $entitlement);

		$this->assertSame(0, $entitlement->getReservedUses());
		$this->assertNotNull($reservation->getReleasedAt());
	}
}
