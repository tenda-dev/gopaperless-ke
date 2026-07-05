<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\DTO;

final class FxEngineResultDTO
{
	public function __construct(
		public readonly int $displayAmount,      // minor units
		public readonly string $displayCurrency, // ISO code
		public readonly string $fxRate,           // applied rate
		public readonly string $fxRateSource,          // e.g. exchangerate.host
		public readonly \DateTimeImmutable $fxRateLockedAt         // UTC timestamp
	) {}
}
