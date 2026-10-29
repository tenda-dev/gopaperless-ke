<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Libresign\Service\Operations\Analytics\Exception\InvalidRangeException;

/**
 * Represents an inclusive calendar-day range in a display timezone.
 *
 * Converts the local start and the day after the local end into a half-open
 * UTC interval [startUtc, endUtcExclusive) for querying.
 */
final class DateRange {
	public const DEFAULT_TZ = 'Africa/Nairobi';
	/** Limits query volume because timestamps are bucketed into days in PHP. */
	public const MAX_DAYS = 366;
	public const DEFAULT_DAYS = 30;

	private function __construct(
		public readonly string $from,
		public readonly string $to,
		public readonly DateTimeZone $tz,
		public readonly DateTimeImmutable $startUtc,
		public readonly DateTimeImmutable $endUtcExclusive,
	) {
	}

	/**
	 * @param string $from  YYYY-MM-DD (inclusive) or '' for the default window
	 * @param string $to    YYYY-MM-DD (inclusive) or '' for the default window
	 * @throws InvalidRangeException
	 */
	public static function fromRequest(string $from, string $to, string $tz = '', ?DateTimeImmutable $now = null): self {
		$tzName = $tz === '' ? self::DEFAULT_TZ : $tz;
		if (!in_array($tzName, DateTimeZone::listIdentifiers(), true)) {
			throw new InvalidRangeException(InvalidRangeException::INVALID_PARAMS);
		}
		$zone = new DateTimeZone($tzName);

		if ($from === '' && $to === '') {
			$today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone)->setTime(0, 0);
			$fromDay = $today->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');
			$toDay = $today;
		} elseif ($from === '' || $to === '') {
			throw new InvalidRangeException(InvalidRangeException::INVALID_PARAMS);
		} else {
			$fromDay = self::parseDay($from, $zone);
			$toDay = self::parseDay($to, $zone);
		}

		if ($fromDay > $toDay) {
			throw new InvalidRangeException(InvalidRangeException::INVALID_RANGE);
		}
		// Count calendar days rather than elapsed seconds to handle DST correctly.
		$spanDays = (int)(new DateTimeImmutable($fromDay->format('Y-m-d'), new DateTimeZone('UTC')))
			->diff(new DateTimeImmutable($toDay->format('Y-m-d'), new DateTimeZone('UTC')))->days + 1;
		if ($spanDays > self::MAX_DAYS) {
			throw new InvalidRangeException(InvalidRangeException::RANGE_TOO_LARGE);
		}

		$utc = new DateTimeZone('UTC');
		return new self(
			$fromDay->format('Y-m-d'),
			$toDay->format('Y-m-d'),
			$zone,
			$fromDay->setTimezone($utc),
			$toDay->modify('+1 day')->setTimezone($utc),
		);
	}

	/**
	 * Parses a calendar date and rejects invalid or normalized dates.
	 */
	private static function parseDay(string $value, DateTimeZone $zone): DateTimeImmutable {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
		if ($day === false || $day->format('Y-m-d') !== $value) {
			throw new InvalidRangeException(InvalidRangeException::INVALID_PARAMS);
		}
		return $day;
	}

	public function tzName(): string {
		return $this->tz->getName();
	}

	/**
	 * Every local calendar day in the range, in order.
	 *
	 * @return list<string> YYYY-MM-DD
	 */
	public function days(): array {
		$days = [];
		$cursor = new DateTimeImmutable($this->from, new DateTimeZone('UTC'));
		$last = new DateTimeImmutable($this->to, new DateTimeZone('UTC'));
		while ($cursor <= $last) {
			$days[] = $cursor->format('Y-m-d');
			$cursor = $cursor->modify('+1 day');
		}
		return $days;
	}

	/**
	 * Returns the range's local calendar day for a timestamp or UTC database string.
	 */
	public function bucketOf(DateTimeInterface|string $utc): string {
		if (is_string($utc)) {
			$utc = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
		}
		return DateTimeImmutable::createFromInterface($utc)->setTimezone($this->tz)->format('Y-m-d');
	}
}
