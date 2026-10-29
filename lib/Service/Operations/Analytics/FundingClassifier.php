<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics;

use OCA\Libresign\Enum\SponsorshipType;

/**
 * Classifies funding for one sign_request without performing I/O.
 *
 * `metadata.entitlement_consumed` is the primary settlement evidence. It uses
 * the same marker and `!empty()` check as SigningSettlementService.
 *
 * - Marker present: `sponsorship_type = 'requester'` classifies the signing as
 *   requester-sponsored; any other value is classified as self-entitlement.
 *   The column default `'self'` is not proof by itself; it is read only
 *   because the marker already shows that a credit was consumed.
 * - Marker absent: a paid payment for this sign_request classifies it as direct
 *   pay-per-signing; otherwise it is unknown.
 *
 * `sponsorship_type`, sponsorship records and reservations are not evidence of
 * consumption on their own. A reservation's `released_at` is set both when it
 * is cancelled and when it is settled.
 */
final class FundingClassifier {
	public const REQUESTER_SPONSORED = 'requesterSponsored';
	public const SELF_ENTITLEMENT = 'selfEntitlement';
	public const DIRECT_PAY = 'directPay';
	/** No consumption marker and no paid payment: free, unsettled or legacy. Not "unfunded". */
	public const UNKNOWN = 'unknown';

	public const CATEGORIES = [
		self::REQUESTER_SPONSORED,
		self::SELF_ENTITLEMENT,
		self::DIRECT_PAY,
		self::UNKNOWN,
	];

	/** Pool label for a consumption whose entitlement type cannot be resolved. */
	public const UNKNOWN_POOL = 'UNKNOWN';

	/**
	 * Extracts consumption evidence and the entitlement ID from sign_request
	 * metadata. Metadata may be a JSON string or an already-decoded array.
	 * Missing or invalid metadata provides no settlement evidence.
	 *
	 * @return array{consumed: bool, entitlementId: ?int}
	 */
	public static function settlement(mixed $rawMetadata): array {
		$metadata = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
		if (!is_array($metadata)) {
			return ['consumed' => false, 'entitlementId' => null];
		}

		$id = $metadata['entitlementId'] ?? null;
		$valid = is_int($id) || (is_string($id) && ctype_digit($id));

		return [
			'consumed' => !empty($metadata['entitlement_consumed']),
			'entitlementId' => $valid && (int)$id > 0 ? (int)$id : null,
		];
	}

	/**
	 * @return self::REQUESTER_SPONSORED|self::SELF_ENTITLEMENT|self::DIRECT_PAY|self::UNKNOWN
	 */
	public static function classify(bool $consumed, string $sponsorshipType, bool $paidDirectly): string {
		if ($consumed) {
			return $sponsorshipType === SponsorshipType::REQUESTER->value
				? self::REQUESTER_SPONSORED
				: self::SELF_ENTITLEMENT;
		}
		return $paidDirectly ? self::DIRECT_PAY : self::UNKNOWN;
	}
}
