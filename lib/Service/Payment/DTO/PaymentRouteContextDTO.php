<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\PaymentCapability;

/**
 * Output of PaymentContextService::resolveRoute().
 *
 * Carries the resolved route plus the by-products routing produced that
 * later phases need: the E.164 phone (mobile), the country context (FX
 * target currency), and the context metadata block persisted onto the
 * payment
 *
 * @property MnoRoutingResultDTO $route
 * @property PaymentCountryContextDTO|null $countryCtx
 */
final class PaymentRouteContextDTO
{
	public function __construct(
		public readonly MnoRoutingResultDTO $route,
		public readonly PaymentCapability $capability,
		public readonly ?string $e164,
		public readonly ?PaymentCountryContextDTO $countryCtx,
		public readonly array $ctxMetadata,
	) {
	}
}
