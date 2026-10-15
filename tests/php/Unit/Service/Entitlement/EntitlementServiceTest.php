<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Entitlement;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\EntitlementType;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Product\ProductService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Covers the admin-grant seam and the read-only overview aggregation.
 */
final class EntitlementServiceTest extends TestCase {
	private EntitlementMapper&MockObject $entitlementMapper;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IDBConnection&MockObject $db;
	private ProductService&MockObject $productService;
	private LoggerInterface&MockObject $logger;
	private EntitlementService $service;

	public function setUp(): void {
		parent::setUp();
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->productService = $this->createMock(ProductService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new EntitlementService(
			$this->entitlementMapper,
			$this->signRequestMapper,
			$this->db,
			$this->productService,
			$this->logger,
		);
	}

	public function testGrantCreatesAdminGrantedEntitlement(): void {
		$this->entitlementMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Entitlement $e): Entitlement {
				$e->setId(42);
				return $e;
			});
		$this->logger->expects($this->once())->method('info');

		$result = $this->service->grant('recipient', 'SIGN_DOCUMENT', 5, 'admin', 'blocked signing', 'PLANE-1');

		$this->assertSame(42, $result->getId());
		$this->assertSame('recipient', $result->getUserId());
		$this->assertSame('SIGN_DOCUMENT', $result->getProductCode());
		$this->assertSame(5, $result->getRemainingUses());
		$this->assertSame(EntitlementType::ADMIN_GRANTED->value, $result->getType());
	}

	public function testGetProductOverviewAggregatesUsableRows(): void {
		$a = new Entitlement();
		$a->setUserId('u');
		$a->setProductCode('SIGN_DOCUMENT');
		$a->setRemainingUses(2);
		$a->setReservedUses(0);
		$a->setEntitlementType(EntitlementType::PAY_AS_YOU_GO);

		$b = new Entitlement();
		$b->setUserId('u');
		$b->setProductCode('SIGN_DOCUMENT');
		$b->setRemainingUses(2);
		$b->setReservedUses(1);
		$b->setEntitlementType(EntitlementType::ADMIN_GRANTED);

		$this->entitlementMapper->expects($this->once())
			->method('findByUserAndProduct')
			->with('u', 'SIGN_DOCUMENT')
			->willReturn([$a, $b]);

		$overview = $this->service->getProductOverview('u', 'SIGN_DOCUMENT');

		$this->assertSame(3, $overview['summary']['availableUses']);
		$this->assertSame(4, $overview['summary']['remainingUses']);
		$this->assertSame(1, $overview['summary']['reservedUses']);
		$this->assertSame(2, $overview['summary']['activeEntitlements']);
		$this->assertCount(2, $overview['entitlements']);
	}

	public function testGetProductOverviewResolvesNextFromSelectorSkippingUnusable(): void {
		// Highest priority but expired → not usable; must be skipped.
		$promoExpired = new Entitlement();
		$promoExpired->setId(1);
		$promoExpired->setRemainingUses(3);
		$promoExpired->setReservedUses(0);
		$promoExpired->setEntitlementType(EntitlementType::PROMOTIONAL);
		$promoExpired->setExpiresAt(new \DateTime('-1 day'));

		// Next priority, usable → should be chosen.
		$adminUsable = new Entitlement();
		$adminUsable->setId(2);
		$adminUsable->setRemainingUses(5);
		$adminUsable->setReservedUses(0);
		$adminUsable->setEntitlementType(EntitlementType::ADMIN_GRANTED);

		$subUsable = new Entitlement();
		$subUsable->setId(3);
		$subUsable->setRemainingUses(10);
		$subUsable->setReservedUses(0);
		$subUsable->setEntitlementType(EntitlementType::SUBSCRIPTION);

		// The selector returns rows already in priority + FIFO order; the service
		// must NOT re-order and must pick the first canUse() row.
		$this->entitlementMapper->method('findActiveByUserAndProduct')
			->with('u', 'SIGN_DOCUMENT')
			->willReturn([$promoExpired, $adminUsable, $subUsable]);
		$this->entitlementMapper->method('findByUserAndProduct')
			->willReturn([$promoExpired, $adminUsable, $subUsable]);

		$overview = $this->service->getProductOverview('u', 'SIGN_DOCUMENT');

		$this->assertSame(2, $overview['summary']['nextEntitlementId']);
	}
}
