<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Db;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Enum\EntitlementType;
use OCP\IDBConnection;
use OCP\Server;

/**
 * @group DB
 */
final class EntitlementMapperTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private EntitlementMapper $mapper;
	private IDBConnection $db;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(EntitlementMapper::class);
		$this->db = Server::get(IDBConnection::class);
	}

	/**
	 * The approved business rule: a free promotional credit must be consumed
	 * before a purchased PAY_AS_YOU_GO credit, EVEN when the promotional row is
	 * newer. This proves the ordering is driven by type priority, not by
	 * created_at and not by the old (alphabetical) type ordering, under which
	 * PAY_AS_YOU_GO would sort first.
	 */
	public function testPromotionalIsSelectedBeforePayAsYouGo(): void {
		$user = 'entmap-promo-first';
		$product = 'ENTMAP_PRODUCT_A';

		// Purchased credit created FIRST (older).
		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::PAY_AS_YOU_GO, '2026-01-01 00:00:00',
		));
		// Free promotional credit created LATER (newer).
		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::PROMOTIONAL, '2026-06-01 00:00:00',
		));

		$types = $this->typesInOrder($user, $product);

		$this->assertSame(
			[
				EntitlementType::PROMOTIONAL->value,
				EntitlementType::PAY_AS_YOU_GO->value,
			],
			$types,
			'Promotional credits must be ordered before purchased credits.',
		);
	}

	/**
	 * Full, deterministic priority across every type:
	 * PROMOTIONAL, ADMIN_GRANTED, SUBSCRIPTION, PAY_AS_YOU_GO.
	 * Inserted deliberately out of priority order to prove the ordering.
	 */
	public function testFullTypePriorityOrder(): void {
		$user = 'entmap-full-order';
		$product = 'ENTMAP_PRODUCT_B';

		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::PAY_AS_YOU_GO, '2026-01-01 00:00:00',
		));
		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::SUBSCRIPTION, '2026-01-02 00:00:00',
		));
		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::ADMIN_GRANTED, '2026-01-03 00:00:00',
		));
		$this->mapper->insert($this->makeEntitlement(
			$user, $product, EntitlementType::PROMOTIONAL, '2026-01-04 00:00:00',
		));

		$this->assertSame(
			[
				EntitlementType::PROMOTIONAL->value,
				EntitlementType::ADMIN_GRANTED->value,
				EntitlementType::SUBSCRIPTION->value,
				EntitlementType::PAY_AS_YOU_GO->value,
			],
			$this->typesInOrder($user, $product),
		);
	}

	/**
	 * Within the same priority, FIFO (created_at ASC) is preserved.
	 */
	public function testFifoWithinSameType(): void {
		$user = 'entmap-fifo';
		$product = 'ENTMAP_PRODUCT_C';

		$newer = $this->makeEntitlement($user, $product, EntitlementType::PROMOTIONAL, '2026-05-01 00:00:00');
		$newer->setRemainingUses(3);
		$this->mapper->insert($newer);

		$older = $this->makeEntitlement($user, $product, EntitlementType::PROMOTIONAL, '2026-02-01 00:00:00');
		$older->setRemainingUses(7);
		$this->mapper->insert($older);

		$rows = $this->mapper->findActiveByUserAndProduct($user, $product);

		$this->assertCount(2, $rows);
		$this->assertSame(7, $rows[0]->getRemainingUses(), 'Older promotional row must come first (FIFO).');
		$this->assertSame(3, $rows[1]->getRemainingUses());
	}

	/**
	 * @return list<string> entitlement type values in returned order
	 */
	private function typesInOrder(string $user, string $product): array {
		return array_map(
			static fn (Entitlement $e): string => $e->getType(),
			$this->mapper->findActiveByUserAndProduct($user, $product),
		);
	}

	private function makeEntitlement(
		string $userId,
		string $productCode,
		EntitlementType $type,
		string $createdAt,
		int $uses = 10,
	): Entitlement {
		$entitlement = new Entitlement();
		$entitlement->setUserId($userId);
		$entitlement->setProductCode($productCode);
		$entitlement->setRemainingUses($uses);
		$entitlement->setEntitlementType($type);
		$entitlement->setCreatedAt(new \DateTime($createdAt, new \DateTimeZone('UTC')));
		return $entitlement;
	}
}
