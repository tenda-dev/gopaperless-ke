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
use OCA\Libresign\Db\Product;
use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSettlementContextDTO;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementValidationService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class SigningSettlementServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private IDBConnection&MockObject $db;
	private SigningSettlementValidationService&MockObject $validationService;
	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementReservationMapper&MockObject $reservationMapper;
	private EntitlementReservationService&MockObject $reservationService;
	private SignRequestMapper&MockObject $signRequestMapper;
	private LoggerInterface&MockObject $logger;
	private SigningSettlementService $service;

	public function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->validationService = $this->createMock(SigningSettlementValidationService::class);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->reservationMapper = $this->createMock(EntitlementReservationMapper::class);
		$this->reservationService = $this->createMock(EntitlementReservationService::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SigningSettlementService(
			$this->db,
			$this->validationService,
			$this->entitlementMapper,
			$this->reservationMapper,
			$this->reservationService,
			$this->signRequestMapper,
			$this->logger,
		);
	}

	public function testSettleRequesterSponsoredConsumesReservedEntitlement(): void {
		$signRequest = new SignRequest();
		$signRequest->setId(1);

		$product = new Product();
		$product->setCode('SIGN_DOCUMENT');

		$entitlement = new Entitlement();
		$entitlement->setId(10);
		$entitlement->setRemainingUses(5);
		$entitlement->setReservedUses(2);

		$sponsorship = new SignerSponsorship();
		$sponsorship->setSponsorUserId('requester');
		$sponsorship->setSponsorshipType(SponsorshipType::REQUESTER->value);

		$reservation = new EntitlementReservation();
		$reservation->setQuantity(2);
		$reservation->setEntitlementId(10);

		$context = new SigningSettlementContextDTO(
			signerUserId: 'signer',
			signRequest: $signRequest,
			product: $product,
			sponsorship: $sponsorship,
			entitlement: $entitlement,
			reservation: $reservation,
		);

		$this->validationService
			->method('validateCommon')
			->willReturn($context);
		$this->validationService
			->method('validateRequesterSponsored')
			->willReturn($context);
		$this->db
			->expects($this->once())
			->method('beginTransaction');
		$this->db
			->expects($this->once())
			->method('commit');
		$this->db
			->expects($this->never())
			->method('rollBack');

		$result = $this->service->settle(1, 'signer', 'SIGN_DOCUMENT');

		$this->assertSame(3, $result->getRemainingUses());
		$this->assertSame(0, $entitlement->getReservedUses());
		$this->assertNotNull($reservation->getReleasedAt());
		$this->assertTrue($signRequest->getMetadata()['entitlement_consumed']);
	}

	public function testSettleSelfSponsoredConsumesSignerEntitlement(): void {
		$signRequest = new SignRequest();
		$signRequest->setId(1);

		$product = new Product();
		$product->setCode('SIGN_DOCUMENT');

		$entitlement = new Entitlement();
		$entitlement->setId(10);
		$entitlement->setRemainingUses(4);

		$context = new SigningSettlementContextDTO(
			signerUserId: 'signer',
			signRequest: $signRequest,
			product: $product,
			entitlement: $entitlement,
		);

		$this->validationService
			->method('validateCommon')
			->willReturn($context);
		$this->validationService
			->method('validateSelfSponsored')
			->willReturn($context);

		$result = $this->service->settle(1, 'signer', 'SIGN_DOCUMENT');

		$this->assertSame(3, $result->getRemainingUses());
		$this->assertTrue($signRequest->getMetadata()['entitlement_consumed']);
	}

	public function testSettleRollsBackOnValidationFailure(): void {
		$this->validationService
			->method('validateCommon')
			->willThrowException(new \RuntimeException('Validation failed'));
		$this->db
			->expects($this->once())
			->method('beginTransaction');
		$this->db
			->expects($this->never())
			->method('commit');
		$this->db
			->expects($this->once())
			->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Validation failed');

		$this->service->settle(1, 'signer', 'SIGN_DOCUMENT');
	}
}
