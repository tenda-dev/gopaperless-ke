<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Single source of truth for time in the GoPaperless side of the application.
 *
 * Centralises UTC "now", ATOM formatting, and tolerant parsing that were
 * otherwise hand-rolled across services. All instants produced are UTC;
 * callers never construct timezones.
 *
 * Injected rather than static so tests and time-sensitive features can
 * substitute a fixed clock — the one thing a clock service most needs.
 *
 * Not used by the Payment entity: Nextcloud entities are built by the ORM
 * via reflection, not the DI container, so they cannot receive injected
 * dependencies and keep their own time helpers by necessity.
 */
class DateTimeService
{
	private const UTC = 'UTC';

	/**
	 * Current instant as an immutable UTC DateTime.
	 * For comparisons, arithmetic, and in-memory use.
	 */
	public function nowImmutable(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone(self::UTC));
	}

	/**
	 * Current instant as an ATOM string (UTC).
	 * For persistence — the storage layer holds ATOM strings.
	 */
	public function now(): string
	{
		return $this->nowImmutable()->format(DATE_ATOM);
	}

	/**
	 * Convert a stored string or DateTime into an immutable UTC DateTime.
	 * Returns null for null or unparseable input — never throws.
	 * Hydrated rows may hold either a DateTimeInterface or a raw string.
	 */
	public function asDateTime(DateTimeInterface|string|null $value): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Format a DateTime as an ATOM string, or null if none given.
	 * Pure formatting: formats what it is given and does not convert
	 * timezone. Normalise with asDateTime() first if UTC is required.
	 */
	public function toAtom(?DateTimeInterface $value): ?string
	{
		return $value?->format(DATE_ATOM);
	}

	/**
	 * Current instant minus the given seconds (UTC immutable).
	 * For "older than N seconds" checks — expiry and stale windows.
	 */
	public function nowMinusSeconds(int $seconds): DateTimeImmutable
	{
		return $this->nowImmutable()->modify("-{$seconds} seconds");
	}

	/**
	 * Current instant plus the given seconds (UTC immutable).
	 * For scheduling — verification backoff and similar.
	 */
	public function nowPlusSeconds(int $seconds): DateTimeImmutable
	{
		return $this->nowImmutable()->modify("+{$seconds} seconds");
	}
}
