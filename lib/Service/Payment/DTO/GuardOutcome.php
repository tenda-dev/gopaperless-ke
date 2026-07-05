<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Db\Payment;

/**
 * The non-throwing half of PaymentValidationService::guard()'s three-way
 * result. A throw is the third arm and needs no representation here.
 *
 * - returnExisting($payment): dedup/idempotency matched an existing row the
 *   caller should surface as-is (paid, reusable-pending, or reconciled-to-paid).
 * - proceed($attemptId): no existing row; continue to price/create using the
 *   resolved attempt id (guard owns id resolution because it owns idempotency).
 */
final class GuardOutcome
{
	private function __construct(
		public readonly ?Payment $existingPayment,
		public readonly ?string $resolvedAttemptId,
	) {
	}

	public static function returnExisting(Payment $payment): self
	{
		return new self($payment, null);
	}

	public static function proceed(string $attemptId): self
	{
		return new self(null, $attemptId);
	}

	public function shouldReturnExisting(): bool
	{
		return $this->existingPayment !== null;
	}
}
