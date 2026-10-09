<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\PaymentProvider;

final class PaymentRoutingResultDTO {
	public function __construct(
		public readonly PhoneMnoIdentityDTO $identity,
		public readonly MnoRoutingResultDTO $route,
		public readonly PaymentCountryContextDTO $countryContext,
		public readonly ?PaymentProvider $providerOverride,
		public readonly ?string $providerMnoKey,
		public readonly AutoChargeDTO $autoCharge,
	) {
	}

	public function toArray(): array {
		return [
			'valid' => $this->identity->valid,
			'phone' => [
				'e164' => $this->identity->e164,
				'national' => $this->identity->national,
				'region' => $this->identity->region,
				'carrierHint' => $this->identity->carrierHint,
			],
			'country' => $this->countryContext->toArray(),
			'routing' => [
				'mno' => $this->identity->mno,
				'provider' => $this->route->preferredProvider->value,
				'confidence' => $this->route->confidence->value,
				'override' => $this->providerOverride?->value,
				'providerMnoKey' => $this->providerMnoKey,
				'mnoKey' => $this->route->mnoKey,
				'requiresProviderSelection' => $this->route->requiresUserSelection(),
			],
			'autoCharge' => $this->autoCharge->toArray(),
		];
	}
}
