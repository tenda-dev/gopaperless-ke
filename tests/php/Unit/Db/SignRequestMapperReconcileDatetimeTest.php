<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Db;

use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCP\Server;

/**
 * Datetime-correctness of the reconciliation scan predicates.
 *
 * `libresign_sign_request.signed` is a SQL DATETIME. These tests prove the
 * boundary and keyset predicates compare instant-for-instant against that
 * column (the fix binds a UTC \DateTime, not an integer epoch). Against the
 * pre-fix integer bind, MariaDB coerced the DATETIME to a YYYYMMDDHHMMSS
 * number, making every predicate uniformly true/false — these assertions would
 * then fail.
 *
 * Runs against the configured test DB; meaningful on MariaDB (the deployed
 * engine). Assertions check membership of this test's own rows only, so
 * pre-existing signed rows in the DB do not affect the result.
 *
 * @group DB
 */
final class SignRequestMapperReconcileDatetimeTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private SignRequestMapper $mapper;

	/** Fixed, whole-second UTC epoch used as the comparison boundary T. */
	private const T = 1_700_000_000; // 2023-11-14 22:13:20 UTC

	/** @var SignRequest[] inserted rows, torn down after each test */
	private array $inserted = [];

	public function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(SignRequestMapper::class);
		$this->inserted = [];
	}

	public function tearDown(): void {
		foreach ($this->inserted as $row) {
			try {
				$this->mapper->delete($row);
			} catch (\Throwable) {
				// best-effort cleanup
			}
		}
		parent::tearDown();
	}

	public function testStrictExclusiveUpperBoundExcludesRowExactlyAtBoundary(): void {
		$below = $this->insertSigned(self::T - 10);
		$atBoundary = $this->insertSigned(self::T);

		// scan 2a: signed < T (strict).
		$ids = $this->idsOf($this->mapper->findReconcileKeysetPage(null, null, self::T, 1000));

		$this->assertContains($below->getId(), $ids, 'row 10s below the boundary must be in the stabilized region');
		$this->assertNotContains($atBoundary->getId(), $ids, 'row exactly at the boundary must be excluded (strict <)');
	}

	public function testInclusiveLowerBoundIncludesRowExactlyAtBoundary(): void {
		$below = $this->insertSigned(self::T - 10);
		$atBoundary = $this->insertSigned(self::T);

		// scan 2b-ii: signed >= T (inclusive).
		$ids = $this->idsOf($this->mapper->findReconcileAtOrAbovePage(self::T, null, null, 1000));

		$this->assertContains($atBoundary->getId(), $ids, 'row exactly at the boundary must be included (inclusive >=)');
		$this->assertNotContains($below->getId(), $ids, 'row below the boundary must be excluded');
	}

	public function testEqualsPageMatchesExactSecondOnly(): void {
		$atT_a = $this->insertSigned(self::T);
		$atT_b = $this->insertSigned(self::T);
		$atTPlus1 = $this->insertSigned(self::T + 1);

		// scan 2b-i: signed = T.
		$ids = $this->idsOf($this->mapper->findReconcileEqualsPage(self::T, null, 1000));

		$this->assertContains($atT_a->getId(), $ids);
		$this->assertContains($atT_b->getId(), $ids);
		$this->assertNotContains($atTPlus1->getId(), $ids, 'a row one second later must not match equality on T');

		// id paging within the same-second group.
		$lowId = min($atT_a->getId(), $atT_b->getId());
		$highId = max($atT_a->getId(), $atT_b->getId());
		$pagedIds = $this->idsOf($this->mapper->findReconcileEqualsPage(self::T, $lowId, 1000));
		$this->assertContains($highId, $pagedIds);
		$this->assertNotContains($lowId, $pagedIds, 'afterId must exclude the row at that id');
	}

	public function testKeysetIsStrictlyAfterCompositePosition(): void {
		// Two rows share second T; one is a second later.
		$a = $this->insertSigned(self::T);
		$b = $this->insertSigned(self::T);
		$later = $this->insertSigned(self::T + 5);

		$lowId = min($a->getId(), $b->getId());
		$highId = max($a->getId(), $b->getId());

		// Keyset position = (T, lowId). Expect strictly after: (T, highId) and
		// (T+5, later); exclude (T, lowId) itself and anything (T, id<lowId).
		$ids = $this->idsOf($this->mapper->findReconcileKeysetPage(self::T, $lowId, null, 1000));

		$this->assertContains($highId, $ids, 'same-second row with greater id is strictly after');
		$this->assertContains($later->getId(), $ids, 'later-second row is strictly after');
		$this->assertNotContains($lowId, $ids, 'the keyset anchor row itself must be excluded');
	}

	public function testResultsOrderedBySignedThenId(): void {
		// Insert deliberately out of order.
		$mid = $this->insertSigned(self::T + 100);
		$earlyB = $this->insertSigned(self::T);
		$late = $this->insertSigned(self::T + 1000);
		$earlyA = $this->insertSigned(self::T); // same second as earlyB, later id

		$ids = $this->idsOf($this->mapper->findReconcileKeysetPage(self::T - 1, null, self::T + 2000, 1000));

		// Expected order among OUR rows: (T, earlyB), (T, earlyA), (T+100, mid), (T+1000, late)
		$mine = [$earlyA->getId(), $earlyB->getId(), $mid->getId(), $late->getId()];
		$observed = array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $mine, true)));

		$expected = [$earlyB->getId(), $earlyA->getId(), $mid->getId(), $late->getId()];
		$this->assertSame($expected, $observed, 'rows must come back ordered by (signed ASC, id ASC)');
	}

	private function insertSigned(int $epoch, int $status = 2): SignRequest {
		$row = new SignRequest();
		$row->setFileId(999_000); // test-only file id
		$row->setUuid('recon-dt-test-' . bin2hex(random_bytes(6)));
		$row->setDisplayName('recon-dt');
		$row->setCreatedAt(new \DateTime('@' . ($epoch - 3600)));
		$row->setSigned(new \DateTime('@' . $epoch)); // '@' => UTC, matching how `signed` is stored
		$row->setStatus($status); // 2 = SIGNED
		$inserted = $this->mapper->insert($row);
		$this->inserted[] = $inserted;
		return $inserted;
	}

	/**
	 * @param SignRequest[] $rows
	 * @return int[]
	 */
	private function idsOf(array $rows): array {
		return array_map(static fn (SignRequest $r): int => $r->getId(), $rows);
	}
}
