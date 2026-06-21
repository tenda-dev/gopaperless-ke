<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\DTO;

final class PaymentCountryContextDTO
{
	public function __construct(
		public readonly string $region,        // KE
		public readonly string $country,       // kenya
		public readonly string $currency,      // KES
		public readonly ?string $altCurrency,
		public readonly bool $supportsDecimals,
	) {}
}
