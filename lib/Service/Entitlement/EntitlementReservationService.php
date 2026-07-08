<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Entitlement;

use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCP\DB\Exception;
use RuntimeException;


/**
 * Manages entitlement reservations.
 *
 * Responsibilities:
 * - Reserve entitlement capacity.
 * - Release entitlement capacity.
 * - Query reserved entitlement usage.
 *
 * This service contains reservation business logic only.
 *
 * Transaction ownership belongs to the caller.
 */
class EntitlementReservationService
{
	public function __construct(
		private EntitlementMapper $entitlementMapper,
		private EntitlementReservationMapper $reservationMapper,
	) {}

	/**
	 * Reserve entitlement capacity for a workflow.
	 *
	 * Example:
	 * - entitlement A = 5 available
	 * - entitlement B = 10 available
	 * - reserve 7
	 *
	 * Result:
	 * - A reserves 5
	 * - B reserves 2
	 *
	 * IMPORTANT:
	 * This method does not manage database transactions.
	 * Transaction ownership belongs to the caller (typically
	 * SponsorshipReservationSyncService) so that reservation and
	 * sponsorship persistence succeed or fail atomically.
	 *
	 * @throws Exception
	 */
	public function reserveForWorkflow(
		string $userId,
		string $productCode,
		int $fileId,
		int $signRequestId,
		int $quantity = 1,
	): void {

		if ($quantity <= 0) {
			throw new RuntimeException(
				'Reservation quantity must be greater than zero'
			);
		}

		$remaining = $quantity;

		$entitlements = $this->entitlementMapper
			->findActiveByUserAndProduct(
				$userId,
				$productCode,
			);

		foreach ($entitlements as $entitlement) {

			if ($remaining <= 0) {
				break;
			}

			$available = $entitlement->getAvailableUses();

			if ($available === null) {
				$available = $remaining;
			}

			if ($available <= 0) {
				continue;
			}

			$reserveAmount = min(
				$available,
				$remaining,
			);

			$reservation = new EntitlementReservation();

			$reservation->setEntitlementId(
				$entitlement->getId(),
			);

			$reservation->setSponsorUserId(
				$userId,
			);

			$reservation->setFileId(
				$fileId,
			);

			$reservation->setSignRequestId(
				$signRequestId,
			);

			$reservation->setQuantity(
				$reserveAmount,
			);

			$this->reservationMapper->insert(
				$reservation,
			);

			$entitlement->setReservedUses(
				($entitlement->getReservedUses() ?? 0)
					+ $reserveAmount,
			);

			$this->entitlementMapper->update(
				$entitlement,
			);

			$remaining -= $reserveAmount;
		}

		if ($remaining > 0) {
			throw new RuntimeException(
				sprintf(
					'Insufficient entitlement capacity. Missing %d reservation(s).',
					$remaining,
				),
			);
		}
	}

	/**
	 * Release reserved entitlement capacity.
	 *
	 * Used when:
	 * - workflow cancelled
	 * - signer removed before signing
	 * - sponsorship revoked
	 * - signing completed (consumption occurs separately)
	 *
	 * IMPORTANT:
	 * This method does not manage database transactions.
	 * Transaction ownership belongs to the caller so that
	 * reservation release and sponsorship deletion remain atomic.
	 *
	 * @throws Exception
	 */
	public function releaseForSignRequest(
		int $signRequestId,
	): void {

		$reservation = $this->reservationMapper
			->findActiveBySignRequestId(
				$signRequestId,
			);

		if ($reservation === null) {
			return;
		}

		/**
		 * Already released.
		 */
		if ($reservation->getReleasedAt() !== null) {
			return;
		}

		$entitlement = $this->entitlementMapper
			->findById(
				$reservation->getEntitlementId(),
			);

		if ($entitlement === null) {
			throw new RuntimeException(
				'Entitlement not found for reservation',
			);
		}

		$currentReserved =
			$entitlement->getReservedUses() ?? 0;

		$newReserved = max(
			0,
			$currentReserved - $reservation->getQuantity(),
		);

		$entitlement->setReservedUses(
			$newReserved,
		);

		$this->entitlementMapper->update(
			$entitlement,
		);

		$reservation->setReleasedAt(
			(new \DateTimeImmutable(
				'now',
				new \DateTimeZone('UTC'),
			))->format(DATE_ATOM),
		);

		$this->reservationMapper->update(
			$reservation,
		);
	}

	public function releaseForWorkflow(
		int $fileId,
	): void {

		$reservations = $this->reservationMapper
			->findActiveByFileId(
				$fileId
			);

		foreach ($reservations as $reservation) {
			$this->releaseForSignRequest(
				$reservation->getSignRequestId()
			);
		}
	}

	/**
	 * Aggregate reserved usage across all
	 * active entitlements for a product.
	 *
	 * @throws Exception
	 */
	public function getReservedUses(
		string $userId,
		string $productCode,
	): int {

		$total = 0;

		$entitlements = $this->entitlementMapper
			->findActiveByUserAndProduct(
				$userId,
				$productCode
			);

		foreach ($entitlements as $entitlement) {
			$total += $entitlement->getReservedUses() ?? 0;
		}

		return $total;
	}
}
