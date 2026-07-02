<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

/**
 * Small, deterministic helper for payment date/time operations.
 *
 * Centralising these helpers makes it easy to stub time in tests
 * and removes duplication from the payment services.
 */
class PaymentDateTimeHelper {
	public function now(): string {
		return (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		))->format(DATE_ATOM);
	}

	public function nowImmutable(): \DateTimeImmutable {
		return new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		);
	}

	public function asDateTime(
		\DateTimeInterface|string|null $value,
	): ?\DateTimeImmutable {

		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($value);
		}

		try {
			return new \DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}
	}
}
