<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use DateTimeZone;
use InvalidArgumentException;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;

/**
 * Immutable admin-configured schedule for the period email report.
 *
 * The schedule only decides WHEN a report is sent; the reporting period is
 * calendar-aligned (see PeriodWindow).
 */
final class PeriodSchedule {
	public const DEFAULT_WEEKDAY = 1;
	public const DEFAULT_DAY_OF_MONTH = 1;
	public const DEFAULT_TIME = '09:00';
	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

	/**
	 * @param int $weekday ISO weekday, 1 = Monday .. 7 = Sunday
	 * @param int $dayOfMonth 1..28 so every month has the day
	 * @param string $time HH:MM (24h), local to $timezone
	 */
	public function __construct(
		public readonly PeriodFrequency $frequency,
		public readonly int $weekday,
		public readonly int $dayOfMonth,
		public readonly string $time,
		public readonly string $timezone,
	) {
	}

	public static function default(): self {
		return new self(
			PeriodFrequency::WEEKLY,
			self::DEFAULT_WEEKDAY,
			self::DEFAULT_DAY_OF_MONTH,
			self::DEFAULT_TIME,
			DateRange::DEFAULT_TZ,
		);
	}

	/**
	 * Strict parser for admin input. Missing keys take the defaults; present
	 * but invalid values are rejected.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function fromArray(mixed $data): self {
		if (!is_array($data)) {
			throw new InvalidArgumentException('Schedule must be an object');
		}
		$default = self::default();

		$frequency = $default->frequency;
		if (array_key_exists('frequency', $data)) {
			$frequency = is_string($data['frequency']) ? PeriodFrequency::tryFrom($data['frequency']) : null;
			if ($frequency === null) {
				throw new InvalidArgumentException('Invalid frequency');
			}
		}

		$weekday = self::intInRange($data, 'weekday', 1, 7, $default->weekday);
		$dayOfMonth = self::intInRange($data, 'dayOfMonth', 1, 28, $default->dayOfMonth);

		$time = $default->time;
		if (array_key_exists('time', $data)) {
			if (!is_string($data['time']) || preg_match(self::TIME_PATTERN, $data['time']) !== 1) {
				throw new InvalidArgumentException('Invalid time');
			}
			$time = $data['time'];
		}

		$timezone = $default->timezone;
		if (array_key_exists('timezone', $data)) {
			if (!is_string($data['timezone']) || !in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
				throw new InvalidArgumentException('Invalid timezone');
			}
			$timezone = $data['timezone'];
		}

		return new self($frequency, $weekday, $dayOfMonth, $time, $timezone);
	}

	/**
	 * Lenient parser for the background job: never throws. Any invalid or
	 * missing field falls back to its default.
	 */
	public static function fromStoredOrDefault(mixed $data): self {
		try {
			return self::fromArray($data);
		} catch (InvalidArgumentException) {
		}
		if (!is_array($data)) {
			return self::default();
		}
		// Field-wise fallback so one bad field does not discard the rest.
		$clean = [];
		foreach (['frequency', 'weekday', 'dayOfMonth', 'time', 'timezone'] as $key) {
			if (!array_key_exists($key, $data)) {
				continue;
			}
			try {
				self::fromArray([$key => $data[$key]]);
				$clean[$key] = $data[$key];
			} catch (InvalidArgumentException) {
			}
		}
		return self::fromArray($clean);
	}

	/**
	 * @return array{frequency: string, weekday: int, dayOfMonth: int, time: string, timezone: string}
	 */
	public function toArray(): array {
		return [
			'frequency' => $this->frequency->value,
			'weekday' => $this->weekday,
			'dayOfMonth' => $this->dayOfMonth,
			'time' => $this->time,
			'timezone' => $this->timezone,
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private static function intInRange(array $data, string $key, int $min, int $max, int $default): int {
		if (!array_key_exists($key, $data)) {
			return $default;
		}
		$value = $data[$key];
		if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
			$value = (int)$value;
		}
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new InvalidArgumentException('Invalid ' . $key);
		}
		return $value;
	}
}
