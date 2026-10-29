<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Libresign\Service\Operations\Analytics\Exception\InvalidRangeException;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DateRangeTest extends TestCase {
	public function testEatBoundariesConvertToUtcHalfOpen(): void {
		$r = DateRange::fromRequest('2026-10-01', '2026-10-28');

		// 00:00 EAT (UTC+3) on Oct 1 = 21:00Z on Sep 30; end is 00:00 EAT Oct 29 = 21:00Z Oct 28.
		$this->assertSame('2026-09-30 21:00:00', $r->startUtc->format('Y-m-d H:i:s'));
		$this->assertSame('2026-10-28 21:00:00', $r->endUtcExclusive->format('Y-m-d H:i:s'));
		$this->assertSame('Africa/Nairobi', $r->tzName());
	}

	public function testUtcLateEveningLandsOnNextEatDay(): void {
		$r = DateRange::fromRequest('2026-10-28', '2026-10-29');

		$this->assertSame('2026-10-29', $r->bucketOf('2026-10-28 23:30:00'));
		$this->assertSame('2026-10-28', $r->bucketOf('2026-10-28 20:59:59'));
		$this->assertSame('2026-10-29', $r->bucketOf('2026-10-28 21:00:00'));
	}

	public function testBothEndsAreInclusive(): void {
		$r = DateRange::fromRequest('2026-10-28', '2026-10-28');

		$this->assertSame(['2026-10-28'], $r->days());
		$this->assertSame(86400, $r->endUtcExclusive->getTimestamp() - $r->startUtc->getTimestamp());
	}

	public function testExactly366DaysIsAllowedAndOneMoreIsNot(): void {
		$ok = DateRange::fromRequest('2025-10-29', '2026-10-29'); // 366 inclusive days
		$this->assertCount(366, $ok->days());

		$this->expectException(InvalidRangeException::class);
		$this->expectExceptionMessage('range_too_large');
		DateRange::fromRequest('2025-10-28', '2026-10-29'); // 367
	}

	public function testFromAfterToIsInvalidRange(): void {
		try {
			DateRange::fromRequest('2026-10-29', '2026-10-28');
			$this->fail('expected exception');
		} catch (InvalidRangeException $e) {
			$this->assertSame('invalid_range', $e->reason);
		}
	}

	public static function malformedProvider(): array {
		return [
			'bad format' => ['28-10-2026', '2026-10-28', ''],
			'impossible date' => ['2026-02-30', '2026-03-01', ''],
			'datetime not date' => ['2026-10-01T00:00', '2026-10-02', ''],
			'only from' => ['2026-10-01', '', ''],
			'only to' => ['', '2026-10-01', ''],
			'unknown tz' => ['2026-10-01', '2026-10-02', 'Mars/Olympus'],
		];
	}

	#[DataProvider('malformedProvider')]
	public function testMalformedInputIsInvalidParams(string $from, string $to, string $tz): void {
		try {
			DateRange::fromRequest($from, $to, $tz);
			$this->fail('expected exception');
		} catch (InvalidRangeException $e) {
			$this->assertSame('invalid_params', $e->reason);
		}
	}

	public function testDefaultWindowIsTheLast30LocalDays(): void {
		// 2026-10-28 22:00Z is already Oct 29 in Nairobi.
		$now = new DateTimeImmutable('2026-10-28 22:00:00', new DateTimeZone('UTC'));

		$r = DateRange::fromRequest('', '', '', $now);

		$this->assertSame('2026-10-29', $r->to);
		$this->assertSame('2026-09-30', $r->from);
		$this->assertCount(30, $r->days());
	}

	public function testSpanIsCountedInCalendarDaysAcrossDst(): void {
		// 2026-03-28..2026-03-30 spans the EU DST change; must still be 3 days.
		$r = DateRange::fromRequest('2026-03-28', '2026-03-30', 'Europe/Berlin');
		$this->assertCount(3, $r->days());
	}
}
