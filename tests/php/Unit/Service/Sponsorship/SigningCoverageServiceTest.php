<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Sponsorship;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Sponsorship\SignerSponsorshipService;
use OCA\Libresign\Service\Sponsorship\SigningCoverageService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class SigningCoverageServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private SignerSponsorshipService&MockObject $sponsorshipService;
	private EntitlementService&MockObject $entitlementService;
	private EntitlementReservationMapper&MockObject $reservationMapper;
	private EntitlementMapper&MockObject $entitlementMapper;
	private LoggerInterface&MockObject $logger;
	private SigningCoverageService $service;

	public function setUp(): void {
		parent::setUp();
		$this->sponsorshipService = $this->createMock(SignerSponsorshipService::class);
		$this->entitlementService = $this->createMock(EntitlementService::class);
		$this->reservationMapper = $this->createMock(EntitlementReservationMapper::class);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SigningCoverageService(
			$this->sponsorshipService,
			$this->entitlementService,
			$this->reservationMapper,
			$this->entitlementMapper,
			$this->logger,
		);
	}

	public function testResolveSigningSponsorshipReturnsSelfWhenNoSponsorship(): void {
		$this->sponsorshipService
			->method('findSignRequestSponsorship')
			->with(1)
			->willReturn(null);

		$dto = $this->service->resolveSigningSponsorship(1);

		$this->assertSame(SponsorshipType::SELF, $dto->type);
		$this->assertNull($dto->sponsorUserId);
	}

	public function testResolveSigningSponsorshipReturnsRequesterWhenSponsorshipExists(): void {
		$sponsorship = new SignerSponsorship();
		$sponsorship->setSponsorUserId('requester');
		$sponsorship->setSponsorshipType(SponsorshipType::REQUESTER->value);

		$this->sponsorshipService
			->method('findSignRequestSponsorship')
			->with(1)
			->willReturn($sponsorship);

		$dto = $this->service->resolveSigningSponsorship(1);

		$this->assertSame(SponsorshipType::REQUESTER, $dto->type);
		$this->assertSame('requester', $dto->sponsorUserId);
	}

	public function testResolveSigningCoverageAllowsRequesterSponsoredSigning(): void {
		$sponsorship = new SignerSponsorship();
		$sponsorship->setSponsorUserId('requester');
		$sponsorship->setSponsorshipType(SponsorshipType::REQUESTER->value);

		$reservation = new EntitlementReservation();
		$reservation->setEntitlementId(10);

		$entitlement = new Entitlement();
		$entitlement->setId(10);

		$this->sponsorshipService
			->method('findSignRequestSponsorship')
			->with(1)
			->willReturn($sponsorship);
		$this->reservationMapper
			->method('findActiveBySignRequestId')
			->with(1)
			->willReturn($reservation);
		$this->entitlementMapper
			->method('findById')
			->with(10)
			->willReturn($entitlement);

		$dto = $this->service->resolveSigningCoverage(1, 'signer', 'SIGN_DOCUMENT');

		$this->assertTrue($dto->allowed);
		$this->assertTrue($dto->sponsored);
		$this->assertSame('requester', $dto->sponsorUserId);
	}

	public function testResolveSigningCoverageFallsBackToSelfSponsored(): void {
		$this->sponsorshipService
			->method('findSignRequestSponsorship')
			->with(1)
			->willReturn(null);
		$this->entitlementService
			->method('canUse')
			->with('signer', 'SIGN_DOCUMENT')
			->willReturn(true);

		$dto = $this->service->resolveSigningCoverage(1, 'signer', 'SIGN_DOCUMENT');

		$this->assertTrue($dto->allowed);
		$this->assertFalse($dto->sponsored);
		$this->assertNull($dto->sponsorUserId);
	}

	public function testResolveSigningCoverageDeniesWhenNoCoverage(): void {
		$this->sponsorshipService
			->method('findSignRequestSponsorship')
			->with(1)
			->willReturn(null);
		$this->entitlementService
			->method('canUse')
			->with('signer', 'SIGN_DOCUMENT')
			->willReturn(false);

		$dto = $this->service->resolveSigningCoverage(1, 'signer', 'SIGN_DOCUMENT');

		$this->assertFalse($dto->allowed);
		$this->assertFalse($dto->sponsored);
		$this->assertNull($dto->sponsorUserId);
	}
}
