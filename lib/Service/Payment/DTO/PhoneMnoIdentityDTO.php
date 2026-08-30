<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;

/**
 * Result of resolving a phone number to an MNO identity.
 *
 * This carries ONLY carrier/geo identity. It deliberately does NOT
 * carry a payment provider/rail: DPO vs Daraja stays derived at runtime
 * by MnoRoutingRegistry from `carrier` + `confidence`.
 */
final class PhoneMnoIdentityDTO {
	public function __construct(
		public readonly bool $valid,
		public readonly ?string $e164,
		public readonly ?string $national,
		public readonly ?string $region,
		public readonly ?string $country,
		 // Canonical payment MNO identity.
        public readonly ?string $mno,

        // Raw/normalised carrier information from the phone resolver.
        public readonly ?string $carrierHint,
		public readonly ResolutionConfidence $confidence,
		public readonly PhoneMnoResolutionSource $source,
		public readonly bool $verified = false,
	) {
	}

	public static function invalid(): self {
		return new self(
			valid: false,
			e164: null,
			national: null,
			region: null,
			country: null,
			mno: null,
			carrierHint: null,
			confidence: ResolutionConfidence::UNKNOWN,
			source: PhoneMnoResolutionSource::NONE,
			verified: false,
		);
	}
}
