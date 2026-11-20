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
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Exception\StaleEntitlementException;
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

		$result = $this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::HTTP_AFTER_SIGN);

		$this->assertSame(3, $result->getRemainingUses());
		$this->assertSame(0, $entitlement->getReservedUses());
		$this->assertNotNull($reservation->getReleasedAt());
		$this->assertTrue($signRequest->getMetadata()['entitlement_consumed']);
		// Provenance: the caller's source is recorded on commit.
		$this->assertSame('http_after_sign', $signRequest->getMetadata()['settlement_source']);
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

		$result = $this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::SIGNED_EVENT_LISTENER);

		$this->assertSame(3, $result->getRemainingUses());
		$this->assertTrue($signRequest->getMetadata()['entitlement_consumed']);
		$this->assertSame('signed_event_listener', $signRequest->getMetadata()['settlement_source']);
	}

	/**
	 * Provenance assignment is driven by the caller, not the settlement path.
	 */
	public function testSettlementSourceReflectsTheCaller(): void {
		foreach (SettlementSource::cases() as $source) {
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

			$validation = $this->createMock(SigningSettlementValidationService::class);
			$validation->method('validateCommon')->willReturn($context);
			$validation->method('validateSelfSponsored')->willReturn($context);
			$service = new SigningSettlementService(
				$this->createMock(IDBConnection::class),
				$validation,
				$this->entitlementMapper,
				$this->reservationMapper,
				$this->reservationService,
				$this->signRequestMapper,
				$this->logger,
			);

			$service->settle(1, 'signer', 'SIGN_DOCUMENT', $source);

			$this->assertSame(
				$source->value,
				$signRequest->getMetadata()['settlement_source'],
				'settlement_source must equal the caller-supplied source',
			);
		}
	}

	/**
	 * Preservation: a second committing path must not overwrite the first
	 * committer's source (defensive guard; the idempotency gate normally
	 * prevents a second commit at all).
	 */
	public function testSettlementSourceIsNotOverwrittenWhenAlreadyPresent(): void {
		$signRequest = new SignRequest();
		$signRequest->setId(1);
		// An earlier committer already stamped the source; marker not yet set in
		// this fabricated state so validateCommon (mocked) still lets us through.
		$signRequest->setMetadata(['settlement_source' => 'signed_event_listener']);

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

		$this->validationService->method('validateCommon')->willReturn($context);
		$this->validationService->method('validateSelfSponsored')->willReturn($context);

		$this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::RECONCILIATION_JOB);

		$this->assertSame(
			'signed_event_listener',
			$signRequest->getMetadata()['settlement_source'],
			'the original committer\'s source must be preserved',
		);
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

		$this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::RECONCILIATION_JOB);
	}

	/**
	 * A failed attempt (no commit) records no provenance on the request.
	 */
	public function testFailedSettlementRecordsNoSettlementSource(): void {
		$signRequest = new SignRequest();
		$signRequest->setId(1);
		$product = new Product();
		$product->setCode('SIGN_DOCUMENT');

		$context = new SigningSettlementContextDTO(
			signerUserId: 'signer',
			signRequest: $signRequest,
			product: $product,
		);

		$this->validationService->method('validateCommon')->willReturn($context);
		$this->validationService
			->method('validateSelfSponsored')
			->willThrowException(new InsufficientEntitlementException());

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::HTTP_AFTER_SIGN);
			$this->fail('expected InsufficientEntitlementException');
		} catch (InsufficientEntitlementException) {
			// expected
		}

		$this->assertArrayNotHasKey('settlement_source', $signRequest->getMetadata() ?? []);
		$this->assertArrayNotHasKey('entitlement_consumed', $signRequest->getMetadata() ?? []);
	}

	public function testSettleRetriesSelfSponsoredAfterStaleEntitlementThenSucceeds(): void {
		$signRequest = new SignRequest();
		$signRequest->setId(1);

		$product = new Product();
		$product->setCode('SIGN_DOCUMENT');

		$entitlement = new Entitlement();
		$entitlement->setId(11);
		$entitlement->setRemainingUses(4);

		$context = new SigningSettlementContextDTO(
			signerUserId: 'signer',
			signRequest: $signRequest,
			product: $product,
			entitlement: $entitlement,
		);

		$this->validationService->method('validateCommon')->willReturn($context);

		$excludedSeen = [];
		$this->validationService
			->expects($this->exactly(2))
			->method('validateSelfSponsored')
			->willReturnCallback(function (SigningSettlementContextDTO $ctx, array $excluded = []) use (&$excludedSeen, $context) {
				$excludedSeen[] = $excluded;
				if ($excluded === []) {
					throw new StaleEntitlementException(10, 2);
				}
				return $context;
			});

		$this->db->expects($this->exactly(2))->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::RECONCILIATION_JOB);

		$this->assertSame(3, $result->getRemainingUses());
		$this->assertSame([[], [10]], $excludedSeen);
		// The stale attempt wrote nothing durable; the one committing attempt
		// records the source exactly once.
		$this->assertSame('reconciliation_job', $signRequest->getMetadata()['settlement_source']);
	}

	public function testSettlePropagatesInsufficientEntitlementWithoutFurtherRetry(): void {
		$this->validationService
			->method('validateCommon')
			->willReturn(new SigningSettlementContextDTO(signerUserId: 'signer'));
		$this->validationService
			->expects($this->once())
			->method('validateSelfSponsored')
			->willThrowException(new InsufficientEntitlementException());

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(InsufficientEntitlementException::class);

		$this->service->settle(1, 'signer', 'SIGN_DOCUMENT', SettlementSource::HTTP_AFTER_SIGN);
	}
}
