<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodSchedule;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodWindow;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 2026-10-26 is a Monday. Africa/Nairobi is UTC+3 all year, so Monday 09:00
 * local is 06:00Z.
 */
final class PeriodWindowTest extends TestCase {
	private const FMT = 'Y-m-d\TH:i:s\Z';

	private static function utc(string $iso): DateTimeImmutable {
		return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
	}

	private static function schedule(array $data = []): PeriodSchedule {
		return PeriodSchedule::fromArray($data);
	}

	public static function latestDueCases(): array {
		$wed = ['frequency' => 'weekly', 'weekday' => 3];
		$sun = ['frequency' => 'weekly', 'weekday' => 7];
		$daily = ['frequency' => 'daily'];
		$month1 = ['frequency' => 'monthly', 'dayOfMonth' => 1];
		$month28 = ['frequency' => 'monthly', 'dayOfMonth' => 28];
		$berlin = ['frequency' => 'daily', 'timezone' => 'Europe/Berlin'];
		$newYork = ['frequency' => 'weekly', 'timezone' => 'America/New_York'];

		// [schedule, nowUtc, key, from, to, prevFrom, prevTo, dueAtUtc]
		return [
			'weekly default: Monday 08:59 still reports the previous week' => [[], '2026-10-26T05:59:00Z', 'weekly:2026-10-12', '2026-10-12', '2026-10-18', '2026-10-05', '2026-10-11', '2026-10-19T06:00:00Z'],
			'weekly default: Monday 09:00' => [[], '2026-10-26T06:00:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-26T06:00:00Z'],
			'weekly default: Monday 09:30' => [[], '2026-10-26T06:30:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-26T06:00:00Z'],
			'weekly default: Thursday late run' => [[], '2026-10-29T21:00:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-26T06:00:00Z'],
			'weekly default: Sunday 23:59 local' => [[], '2026-11-01T20:59:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-26T06:00:00Z'],
			'weekly Wednesday: Monday after period end steps back one period' => [$wed, '2026-11-02T07:00:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-28T06:00:00Z'],
			'weekly Wednesday: at the due instant' => [$wed, '2026-11-04T06:00:00Z', 'weekly:2026-10-26', '2026-10-26', '2026-11-01', '2026-10-19', '2026-10-25', '2026-11-04T06:00:00Z'],
			'weekly Sunday: one minute before' => [$sun, '2026-11-08T05:59:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-11-01T06:00:00Z'],
			'weekly Sunday: at the due instant' => [$sun, '2026-11-08T06:00:00Z', 'weekly:2026-10-26', '2026-10-26', '2026-11-01', '2026-10-19', '2026-10-25', '2026-11-08T06:00:00Z'],
			'daily: before HH:MM' => [$daily, '2026-10-29T05:59:00Z', 'daily:2026-10-27', '2026-10-27', '2026-10-27', '2026-10-26', '2026-10-26', '2026-10-28T06:00:00Z'],
			'daily: at HH:MM' => [$daily, '2026-10-29T06:00:00Z', 'daily:2026-10-28', '2026-10-28', '2026-10-28', '2026-10-27', '2026-10-27', '2026-10-29T06:00:00Z'],
			'monthly day 1: before' => [$month1, '2026-11-01T05:59:00Z', 'monthly:2026-09-01', '2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31', '2026-10-01T06:00:00Z'],
			'monthly day 1: at due (31-day month, previous is 30-day)' => [$month1, '2026-11-01T06:00:00Z', 'monthly:2026-10-01', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30', '2026-11-01T06:00:00Z'],
			'monthly day 28: mid month steps back' => [$month28, '2026-11-15T10:00:00Z', 'monthly:2026-09-01', '2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31', '2026-10-28T06:00:00Z'],
			'monthly day 28: at due' => [$month28, '2026-11-28T06:00:00Z', 'monthly:2026-10-01', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30', '2026-11-28T06:00:00Z'],
			'monthly: February (28 days)' => [$month1, '2026-03-05T10:00:00Z', 'monthly:2026-02-01', '2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31', '2026-03-01T06:00:00Z'],
			'monthly: year boundary' => [$month1, '2027-01-01T06:00:00Z', 'monthly:2026-12-01', '2026-12-01', '2026-12-31', '2026-11-01', '2026-11-30', '2027-01-01T06:00:00Z'],
			'Berlin daily: one minute before the post-DST due instant' => [$berlin, '2026-10-26T07:59:00Z', 'daily:2026-10-24', '2026-10-24', '2026-10-24', '2026-10-23', '2026-10-23', '2026-10-25T08:00:00Z'],
			'Berlin daily: the DST-end day is reported' => [$berlin, '2026-10-26T08:00:00Z', 'daily:2026-10-25', '2026-10-25', '2026-10-25', '2026-10-24', '2026-10-24', '2026-10-26T08:00:00Z'],
			'New York weekly: Monday 08:59 local' => [$newYork, '2026-10-26T12:59:00Z', 'weekly:2026-10-12', '2026-10-12', '2026-10-18', '2026-10-05', '2026-10-11', '2026-10-19T13:00:00Z'],
			'New York weekly: Monday 09:00 local' => [$newYork, '2026-10-26T13:00:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18', '2026-10-26T13:00:00Z'],
		];
	}

	#[DataProvider('latestDueCases')]
	public function testLatestDue(array $schedule, string $now, string $key, string $from, string $to, string $prevFrom, string $prevTo, string $dueAt): void {
		$window = PeriodWindow::latestDue(self::schedule($schedule), self::utc($now));

		$this->assertSame($key, $window->key());
		$this->assertSame($from, $window->current->from);
		$this->assertSame($to, $window->current->to);
		$this->assertSame($prevFrom, $window->previous->from);
		$this->assertSame($prevTo, $window->previous->to);
		$this->assertSame($dueAt, $window->dueAt->format(self::FMT));
		$this->assertTrue($window->dueAt <= self::utc($now), 'latest due period must already be due');
	}

	public static function nextDueCases(): array {
		$wed = ['frequency' => 'weekly', 'weekday' => 3];
		$daily = ['frequency' => 'daily'];
		$month28 = ['frequency' => 'monthly', 'dayOfMonth' => 28];

		// [schedule, nowUtc, key, from, to, dueAtUtc]
		return [
			'weekly default: before Monday 09:00' => [[], '2026-10-26T05:59:00Z', 'weekly:2026-10-19', '2026-10-19', '2026-10-25', '2026-10-26T06:00:00Z'],
			'weekly default: exactly at the due instant moves on' => [[], '2026-10-26T06:00:00Z', 'weekly:2026-10-26', '2026-10-26', '2026-11-01', '2026-11-02T06:00:00Z'],
			'weekly Wednesday: Monday after period end' => [$wed, '2026-11-02T07:00:00Z', 'weekly:2026-10-26', '2026-10-26', '2026-11-01', '2026-11-04T06:00:00Z'],
			'daily: before' => [$daily, '2026-10-29T05:59:00Z', 'daily:2026-10-28', '2026-10-28', '2026-10-28', '2026-10-29T06:00:00Z'],
			'daily: at due moves on' => [$daily, '2026-10-29T06:00:00Z', 'daily:2026-10-29', '2026-10-29', '2026-10-29', '2026-10-30T06:00:00Z'],
			'monthly day 28: after the September report' => [$month28, '2026-10-29T10:00:00Z', 'monthly:2026-10-01', '2026-10-01', '2026-10-31', '2026-11-28T06:00:00Z'],
			'monthly day 28: before the September report' => [$month28, '2026-10-15T10:00:00Z', 'monthly:2026-09-01', '2026-09-01', '2026-09-30', '2026-10-28T06:00:00Z'],
		];
	}

	#[DataProvider('nextDueCases')]
	public function testNextDue(array $schedule, string $now, string $key, string $from, string $to, string $dueAt): void {
		$window = PeriodWindow::nextDue(self::schedule($schedule), self::utc($now));

		$this->assertSame($key, $window->key());
		$this->assertSame($from, $window->current->from);
		$this->assertSame($to, $window->current->to);
		$this->assertSame($dueAt, $window->dueAt->format(self::FMT));
		$this->assertTrue($window->dueAt > self::utc($now), 'next due instant must be in the future');
	}

	public function testUtcBoundariesAreHalfOpen(): void {
		$window = PeriodWindow::latestDue(self::schedule(), self::utc('2026-10-26T06:00:00Z'));

		// 2026-10-19 00:00 EAT .. 2026-10-26 00:00 EAT (exclusive).
		$this->assertSame('2026-10-18T21:00:00Z', $window->current->startUtc->format(self::FMT));
		$this->assertSame('2026-10-25T21:00:00Z', $window->current->endUtcExclusive->format(self::FMT));
		// The comparison period ends exactly where the current one starts.
		$this->assertSame('2026-10-11T21:00:00Z', $window->previous->startUtc->format(self::FMT));
		$this->assertEquals($window->current->startUtc, $window->previous->endUtcExclusive);
	}

	public function testDstDayIsTwentyFiveHoursLong(): void {
		$window = PeriodWindow::latestDue(self::schedule(['frequency' => 'daily', 'timezone' => 'Europe/Berlin']), self::utc('2026-10-26T08:00:00Z'));

		$this->assertSame('daily:2026-10-25', $window->key());
		$this->assertSame('2026-10-24T22:00:00Z', $window->current->startUtc->format(self::FMT));
		$this->assertSame('2026-10-25T23:00:00Z', $window->current->endUtcExclusive->format(self::FMT));
	}

	public function testResultDoesNotDependOnThePhpDefaultTimezone(): void {
		$original = date_default_timezone_get();
		$now = self::utc('2026-10-26T06:00:00Z');
		try {
			date_default_timezone_set('UTC');
			$a = PeriodWindow::latestDue(self::schedule(), $now);
			date_default_timezone_set('Pacific/Kiritimati');
			$b = PeriodWindow::latestDue(self::schedule(), $now);
			$nextB = PeriodWindow::nextDue(self::schedule(), $now);
		} finally {
			date_default_timezone_set($original);
		}

		$this->assertSame($a->key(), $b->key());
		$this->assertSame($a->current->startUtc->format(self::FMT), $b->current->startUtc->format(self::FMT));
		$this->assertSame($a->dueAt->format(self::FMT), $b->dueAt->format(self::FMT));
		$this->assertSame('weekly:2026-10-26', $nextB->key());
	}

	public function testCustomTimeOfDay(): void {
		$schedule = self::schedule(['frequency' => 'daily', 'time' => '23:30']);

		// 23:30 EAT is 20:30Z; one minute before, the day before yesterday is still the latest due period.
		$this->assertSame('daily:2026-10-27', PeriodWindow::latestDue($schedule, self::utc('2026-10-29T20:29:00Z'))->key());
		$this->assertSame('daily:2026-10-28', PeriodWindow::latestDue($schedule, self::utc('2026-10-29T20:30:00Z'))->key());
	}
}
