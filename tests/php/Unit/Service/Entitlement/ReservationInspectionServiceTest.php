<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Entitlement;

use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Service\Entitlement\ReservationInspectionService;
use OCA\Libresign\Service\Sponsorship\SignerSponsorshipService;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ReservationInspectionServiceTest extends TestCase {
	private EntitlementReservationMapper&MockObject $reservationMapper;
	private SignerSponsorshipService&MockObject $sponsorshipService;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IdentifyMethodMapper&MockObject $identifyMethodMapper;
	private ReservationInspectionService $service;

	public function setUp(): void {
		parent::setUp();
		$this->reservationMapper = $this->createMock(EntitlementReservationMapper::class);
		$this->sponsorshipService = $this->createMock(SignerSponsorshipService::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->identifyMethodMapper = $this->createMock(IdentifyMethodMapper::class);
		$this->service = new ReservationInspectionService(
			$this->reservationMapper,
			$this->sponsorshipService,
			$this->signRequestMapper,
			$this->identifyMethodMapper,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function reservation(int $signRequestId, int $quantity): EntitlementReservation {
		$r = new EntitlementReservation();
		$r->setEntitlementId(1);
		$r->setSignRequestId($signRequestId);
		$r->setSponsorUserId('owner');
		$r->setFileId(10);
		$r->setQuantity($quantity);
		return $r;
	}

	private function emailMethod(string $value): IdentifyMethod&MockObject {
		$m = $this->createMock(IdentifyMethod::class);
		$m->method('getIdentifierKey')->willReturn('email');
		$m->method('getIdentifierValue')->willReturn($value);
		return $m;
	}

	public function testSelfReservation(): void {
		$this->reservationMapper->method('findActiveByEntitlementId')->willReturn([$this->reservation(5, 2)]);
		$this->sponsorshipService->method('findSignRequestSponsorship')->with(5)->willReturn(null);
		$this->identifyMethodMapper->expects($this->never())->method('getIdentifyMethodsFromSignRequestId');

		$out = $this->service->forEntitlement(1);

		$this->assertCount(1, $out);
		$this->assertFalse($out[0]['sponsored']);
		$this->assertNull($out[0]['signerName']);
		$this->assertSame(5, $out[0]['signRequestId']);
		$this->assertSame(2, $out[0]['quantity']);
	}

	public function testSponsoredReservationPrefersSignerEmail(): void {
		$this->reservationMapper->method('findActiveByEntitlementId')->willReturn([$this->reservation(9, 3)]);
		$this->sponsorshipService->method('findSignRequestSponsorship')->with(9)->willReturn($this->createMock(SignerSponsorship::class));
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->with(9)->willReturn([$this->emailMethod('bob@example.co.ke')]);
		$this->signRequestMapper->expects($this->never())->method('getById');

		$out = $this->service->forEntitlement(1);

		$this->assertTrue($out[0]['sponsored']);
		$this->assertSame('bob@example.co.ke', $out[0]['signerName']);
	}

	public function testSponsoredWithoutEmailFallsBackToDisplayName(): void {
		$this->reservationMapper->method('findActiveByEntitlementId')->willReturn([$this->reservation(9, 1)]);
		$this->sponsorshipService->method('findSignRequestSponsorship')->willReturn($this->createMock(SignerSponsorship::class));
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([]);
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getDisplayName')->willReturn('Bob Signer');
		$this->signRequestMapper->method('getById')->with(9)->willReturn($signRequest);

		$out = $this->service->forEntitlement(1);

		$this->assertTrue($out[0]['sponsored']);
		$this->assertSame('Bob Signer', $out[0]['signerName']);
	}

	public function testSponsoredButNoIdentityDegradesGracefully(): void {
		$this->reservationMapper->method('findActiveByEntitlementId')->willReturn([$this->reservation(9, 1)]);
		$this->sponsorshipService->method('findSignRequestSponsorship')->willReturn($this->createMock(SignerSponsorship::class));
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willThrowException(new \RuntimeException('x'));
		$this->signRequestMapper->method('getById')->willThrowException(new \RuntimeException('missing'));

		$out = $this->service->forEntitlement(1);

		$this->assertTrue($out[0]['sponsored']);
		$this->assertNull($out[0]['signerName']);
	}

	public function testReservationLoadFailureReturnsEmpty(): void {
		$this->reservationMapper->method('findActiveByEntitlementId')->willThrowException(new \RuntimeException('db'));
		$this->assertSame([], $this->service->forEntitlement(1));
	}
}
