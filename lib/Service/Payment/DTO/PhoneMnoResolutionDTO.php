<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\PaymentProvider;

final class PhoneMnoResolutionDTO {
	public function __construct(
		public readonly PhoneMnoIdentityDTO $identity,
		public readonly ?PaymentProvider $providerOverride = null,
	) {
	}
}
