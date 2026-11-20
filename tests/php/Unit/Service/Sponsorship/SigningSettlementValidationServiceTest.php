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
use OCA\Libresign\Db\Product;
use OCA\Libresign\Db\ProductMapper;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Exception\StaleEntitlementException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSettlementContextDTO;
use OCA\Libresign\Service\Sponsorship\SigningSettlementValidationService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the P0a lock-and-revalidate sequence in validateSelfSponsored():
 * unlocked priority/FIFO selection, exactly one entitlement lock, and the
 * stale-vs-insufficient distinction settle() depends on to decide whether
 * to retry.
 */
final class SigningSettlementValidationServiceTest extends TestCase {
	private SignRequestMapper&MockObject $signRequestMapper;
	private ProductMapper&MockObject $productMapper;
	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementReservationMapper&MockObject $reservationMapper;
	private SignerSponsorshipMapper&MockObject $signerSponsorshipMapper;
	private LoggerInterface&MockObject $logger;
	private ValidateHelper&MockObject $validateHelper;
	private SigningSettlementValidationService $service;

	protected function setUp(): void {
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->productMapper = $this->createMock(ProductMapper::class);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->reservationMapper = $this->createMock(EntitlementReservationMapper::class);
		$this->signerSponsorshipMapper = $this->createMock(SignerSponsorshipMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->validateHelper = $this->createMock(ValidateHelper::class);

		$this->service = new SigningSettlementValidationService(
			$this->signRequestMapper,
			$this->productMapper,
			$this->entitlementMapper,
			$this->reservationMapper,
			$this->signerSponsorshipMapper,
			$this->logger,
			$this->validateHelper,
		);
	}

	private function makeContext(): SigningSettlementContextDTO {
		$product = new Product();
		$product->setCode('SIGN_DOCUMENT');

		$context = new SigningSettlementContextDTO(signerUserId: 'signer');
		return $context->withProduct($product);
	}

	public function testLocksTheTopUnlockedCandidate(): void {
		$context = $this->makeContext();

		$candidate = new Entitlement();
		$candidate->setId(10);
		$candidate->setRemainingUses(3);

		$locked = new Entitlement();
		$locked->setId(10);
		$locked->setRemainingUses(3);

		$this->entitlementMapper
			->expects($this->once())
			->method('findActiveByUserAndProduct')
			->with('signer', 'SIGN_DOCUMENT')
			->willReturn([$candidate]);
		$this->entitlementMapper
			->expects($this->once())
			->method('findByIdForUpdate')
			->with(10)
			->willReturn($locked);

		$result = $this->service->validateSelfSponsored($context);

		$this->assertSame($locked, $result->entitlement);
	}

	public function testSkipsExcludedCandidatesWithoutLockingThem(): void {
		$context = $this->makeContext();

		$stale = new Entitlement();
		$stale->setId(10);
		$stale->setRemainingUses(1);

		$usable = new Entitlement();
		$usable->setId(11);
		$usable->setRemainingUses(2);

		$locked = new Entitlement();
		$locked->setId(11);
		$locked->setRemainingUses(2);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([$stale, $usable]);
		// Never locks the excluded id -- a prior attempt already found it
		// stale, and the single-entitlement-lock-per-TX invariant means
		// this attempt gets exactly one chance.
		$this->entitlementMapper
			->expects($this->once())
			->method('findByIdForUpdate')
			->with(11)
			->willReturn($locked);

		$result = $this->service->validateSelfSponsored($context, [10]);

		$this->assertSame($locked, $result->entitlement);
	}

	public function testThrowsStaleWhenLockedCandidateHasNoRemainingUses(): void {
		$context = $this->makeContext();

		$candidate = new Entitlement();
		$candidate->setId(10);
		$candidate->setRemainingUses(1);

		// Another transaction consumed it between selection and lock.
		$lockedButExhausted = new Entitlement();
		$lockedButExhausted->setId(10);
		$lockedButExhausted->setRemainingUses(0);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([$candidate]);
		$this->entitlementMapper
			->method('findByIdForUpdate')
			->with(10)
			->willReturn($lockedButExhausted);

		try {
			$this->service->validateSelfSponsored($context);
			$this->fail('Expected StaleEntitlementException');
		} catch (StaleEntitlementException $e) {
			$this->assertSame(10, $e->getEntitlementId());
			$this->assertSame(1, $e->getCandidateCount());
		}
	}

	public function testThrowsStaleWhenLockedCandidateHasVanished(): void {
		$context = $this->makeContext();

		$candidate = new Entitlement();
		$candidate->setId(10);
		$candidate->setRemainingUses(1);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([$candidate]);
		$this->entitlementMapper
			->method('findByIdForUpdate')
			->with(10)
			->willReturn(null);

		$this->expectException(StaleEntitlementException::class);
		$this->service->validateSelfSponsored($context);
	}

	public function testThrowsInsufficientWhenEveryCandidateIsExcluded(): void {
		$context = $this->makeContext();

		$candidate = new Entitlement();
		$candidate->setId(10);

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([$candidate]);
		$this->entitlementMapper
			->expects($this->never())
			->method('findByIdForUpdate');

		$this->expectException(InsufficientEntitlementException::class);
		$this->service->validateSelfSponsored($context, [10]);
	}

	public function testThrowsInsufficientWhenNoCandidateExistsAtAll(): void {
		$context = $this->makeContext();

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([]);
		$this->entitlementMapper
			->expects($this->never())
			->method('findByIdForUpdate');

		$this->expectException(InsufficientEntitlementException::class);
		$this->service->validateSelfSponsored($context);
	}

	public function testThrowsStaleWhenLockedCandidateIsExpired(): void {
		$context = $this->makeContext();

		$candidate = new Entitlement();
		$candidate->setId(10);
		$candidate->setRemainingUses(5);

		$lockedButExpired = new Entitlement();
		$lockedButExpired->setId(10);
		$lockedButExpired->setRemainingUses(5);
		$lockedButExpired->setExpiresAt(new \DateTime('yesterday'));

		$this->entitlementMapper
			->method('findActiveByUserAndProduct')
			->willReturn([$candidate]);
		$this->entitlementMapper
			->method('findByIdForUpdate')
			->willReturn($lockedButExpired);

		$this->expectException(StaleEntitlementException::class);
		$this->service->validateSelfSponsored($context);
	}
}
