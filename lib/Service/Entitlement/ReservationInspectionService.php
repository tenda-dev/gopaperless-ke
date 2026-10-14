<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Entitlement;

use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Service\Sponsorship\SignerSponsorshipService;
use Psr\Log\LoggerInterface;

/**
 * Read-only inspection of active reservations for the admin entitlement overview.
 *
 * Sponsorship is determined from the SignerSponsorship record for each sign
 * request, not inferred from the entitlement owner. Missing or unreadable
 * supporting data is handled defensively so inspection failures do not break
 * the overview.
 *
 * This service does not modify reservations, consumption, or settlement.
 */
class ReservationInspectionService {
	public function __construct(
		private EntitlementReservationMapper $reservationMapper,
		private SignerSponsorshipService $sponsorshipService,
		private SignRequestMapper $signRequestMapper,
		private IdentifyMethodMapper $identifyMethodMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array{signRequestId: int, quantity: int, createdAt: ?string, sponsored: bool, signerName: ?string}>
	 */
	public function forEntitlement(int $entitlementId): array {
		try {
			$reservations = $this->reservationMapper->findActiveByEntitlementId($entitlementId);
		} catch (\Throwable $e) {
			$this->logger->warning('[ReservationInspectionService] could not load reservations', [
				'entitlementId' => $entitlementId,
				'exception' => $e,
			]);
			return [];
		}

		$out = [];
		foreach ($reservations as $reservation) {
			$signRequestId = $reservation->getSignRequestId();
			$sponsored = false;
			$signerName = null;

			try {
				// A REQUESTER sponsorship means the entitlement owner is sponsoring
				// the signer; self-sponsored reservations have no such record.
				$sponsored = $this->sponsorshipService->findSignRequestSponsorship($signRequestId) !== null;
			} catch (\Throwable $e) {
				$sponsored = false;
			}

			if ($sponsored) {
				$signerName = $this->resolveSignerLabel($signRequestId);
			}

			$out[] = [
				'signRequestId' => $signRequestId,
				'quantity' => $reservation->getQuantity(),
				'createdAt' => $this->formatDate($reservation->getCreatedAt()),
				'sponsored' => $sponsored,
				'signerName' => $signerName,
			];
		}

		return $out;
	}

	/**
	 * Resolve the sponsored signer's email, falling back to their display name.
	 * Returns null when neither can be resolved.
	 */
	private function resolveSignerLabel(int $signRequestId): ?string {
		try {
			foreach ($this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequestId) as $method) {
				if (strtolower($method->getIdentifierKey()) === 'email') {
					$value = trim($method->getIdentifierValue());
					if ($value !== '') {
						return $value;
					}
				}
			}
		} catch (\Throwable $e) {
			// fall through to display name
		}

		try {
			$name = $this->signRequestMapper->getById($signRequestId)->getDisplayName();
			return ($name !== null && $name !== '') ? $name : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function formatDate(mixed $value): ?string {
		if ($value instanceof \DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}
		if (is_string($value) && $value !== '') {
			return $value;
		}
		return null;
	}
}
