<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;

/**
 * One reporting period plus the one before it, and the instant its report is due.
 *
 * Periods are calendar-aligned and independent of the send day: daily is the
 * local calendar day, weekly is Monday-Sunday, monthly is the calendar month.
 * A period is due at the configured time on the first matching day that is
 * not before the day after its last day: daily is the next day, weekly is the
 * first configured weekday from the following Monday, monthly is the
 * configured day of the following month. A report is therefore never due
 * before its period has fully ended.
 *
 * Let k be the most recent period whose end (the first instant of the next
 * period) is <= now. A period is due strictly less than one cycle after it
 * ends, so due(k-1) < end(k) <= now. Therefore:
 *  - latest due period = k if due(k) <= now, else k-1
 *  - next due period   = k if due(k) >  now, else k+1
 * No iteration is needed.
 *
 * Calendar arithmetic is done on "civil" dates (midnight UTC used purely as a
 * date carrier) so DST never shifts a date. Only the due instant is converted
 * through the schedule's explicit timezone. The PHP default timezone is never
 * consulted.
 */
final class PeriodWindow {
	public readonly string $periodStart;
	/** Inclusive last local day of the period. */
	public readonly string $periodEnd;
	public readonly string $previousStart;
	public readonly string $previousEnd;
	public readonly DateRange $current;
	public readonly DateRange $previous;
	/** UTC instant at which this period's report is due. */
	public readonly DateTimeImmutable $dueAt;

	private function __construct(
		public readonly PeriodSchedule $schedule,
		DateTimeImmutable $startCivil,
		DateTimeImmutable $nowUtc,
	) {
		$frequency = $schedule->frequency;
		$endExclusive = self::shift($startCivil, $frequency, 1);

		$this->periodStart = $startCivil->format('Y-m-d');
		$this->periodEnd = $endExclusive->modify('-1 day')->format('Y-m-d');

		$previousStart = self::shift($startCivil, $frequency, -1);
		$this->previousStart = $previousStart->format('Y-m-d');
		$this->previousEnd = $startCivil->modify('-1 day')->format('Y-m-d');

		$this->current = DateRange::fromRequest($this->periodStart, $this->periodEnd, $schedule->timezone, $nowUtc);
		$this->previous = DateRange::fromRequest($this->previousStart, $this->previousEnd, $schedule->timezone, $nowUtc);
		$this->dueAt = self::computeDue($schedule, $endExclusive);
	}

	public static function latestDue(PeriodSchedule $schedule, DateTimeImmutable $nowUtc): self {
		$k = self::latestEnded($schedule, $nowUtc);
		if ($k->dueAt <= $nowUtc) {
			return $k;
		}
		return new self($schedule, self::civil($k->previousStart), $nowUtc);
	}

	public static function nextDue(PeriodSchedule $schedule, DateTimeImmutable $nowUtc): self {
		$k = self::latestEnded($schedule, $nowUtc);
		if ($k->dueAt > $nowUtc) {
			return $k;
		}
		return new self($schedule, self::shift(self::civil($k->periodStart), $schedule->frequency, 1), $nowUtc);
	}

	/** Dedupe marker, e.g. `weekly:2026-10-19`. */
	public function key(): string {
		return $this->schedule->frequency->value . ':' . $this->periodStart;
	}

	private static function latestEnded(PeriodSchedule $schedule, DateTimeImmutable $nowUtc): self {
		$zone = new DateTimeZone($schedule->timezone);
		$today = self::civil($nowUtc->setTimezone($zone)->format('Y-m-d'));

		$start = match ($schedule->frequency) {
			PeriodFrequency::DAILY => $today->modify('-1 day'),
			PeriodFrequency::WEEKLY => $today->modify('-' . ((int)$today->format('N') - 1) . ' days')->modify('-7 days'),
			PeriodFrequency::MONTHLY => $today->modify('first day of this month')->modify('-1 month'),
		};
		return new self($schedule, $start, $nowUtc);
	}

	private static function computeDue(PeriodSchedule $schedule, DateTimeImmutable $endExclusive): DateTimeImmutable {
		$date = match ($schedule->frequency) {
			PeriodFrequency::DAILY => $endExclusive,
			// endExclusive is a Monday; move forward to the configured ISO weekday.
			PeriodFrequency::WEEKLY => $endExclusive->modify('+' . (($schedule->weekday - (int)$endExclusive->format('N') + 7) % 7) . ' days'),
			// endExclusive is the 1st of the following month.
			PeriodFrequency::MONTHLY => $endExclusive->modify('+' . ($schedule->dayOfMonth - 1) . ' days'),
		};
		return (new DateTimeImmutable($date->format('Y-m-d') . ' ' . $schedule->time . ':00', new DateTimeZone($schedule->timezone)))
			->setTimezone(new DateTimeZone('UTC'));
	}

	private static function shift(DateTimeImmutable $civil, PeriodFrequency $frequency, int $direction): DateTimeImmutable {
		$sign = $direction >= 0 ? '+' : '-';
		return match ($frequency) {
			PeriodFrequency::DAILY => $civil->modify($sign . '1 day'),
			PeriodFrequency::WEEKLY => $civil->modify($sign . '7 days'),
			PeriodFrequency::MONTHLY => $civil->modify($sign . '1 month'),
		};
	}

	private static function civil(string $ymd): DateTimeImmutable {
		return new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC'));
	}
}
