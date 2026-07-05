<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

/**
 * Output of PaymentContextService::price().
 *
 * All money math for one attempt: product-derived minor amount, entitlement
 * uses, the FX result (persisted), and the provider-normalised amount handed
 * to the provider call. The coordinator performs no arithmetic of its own.
 *
 * @property FxEngineResultDTO $fx
 */
final class PaymentPricingDTO
{
	public function __construct(
		public readonly int $amountMinor,
		public readonly string $currency,
		public readonly int $uses,
		public readonly int $unitAmount,
		public readonly int $quantity,
		public readonly FxEngineResultDTO $fx,
		public readonly int|float|string $normalisedAmount,
	) {
	}
}
