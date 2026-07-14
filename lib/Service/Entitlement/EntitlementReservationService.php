<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Entitlement;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;
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
		private LoggerInterface $logger,
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
	 * Used when: workflow cancelled, signer removed before signing,
	 * sponsorship revoked, or signing completed (consumption is separate).
	 *
	 * LOCKING: acquires FOR UPDATE on the reservation, then the
	 * entitlement ALWAYS in that order. Every path that locks both
	 * rows MUST use the same reservation-then-entitlement order or
	 * risk deadlock. The reservation lock is taken via
	 * findActiveBySignRequestIdForUpdate so the released_at decision
	 * is made under lock; a concurrently-released reservation returns
	 * null here and this method naturally short-circuits.
	 *
	 * TRANSACTION: does NOT open a transaction. All FOR UPDATE locks
	 * are inert unless the CALLER has a transaction open. Caller owns
	 * the transaction so reservation release and sponsorship deletion
	 * commit atomically.
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
			->findActiveBySignRequestIdForUpdate(
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

		/**
		 * Acquire an exclusive lock before mutating the entitlement.
		 *
		 * This prevents concurrent settlement and release operations
		 * from updating the same entitlement simultaneously.
		 *
		 * Transaction ownership belongs to the caller.
		 */
		$entitlement = $this->entitlementMapper
			->findByIdForUpdate(
				$reservation->getEntitlementId(),
			);

		if ($entitlement === null) {
			throw new RuntimeException(
				'Entitlement not found for reservation',
			);
		}

		$this->applyReservationRelease(
			$reservation,
			$entitlement,
		);

		$this->entitlementMapper->update(
			$entitlement,
		);

		$this->reservationMapper->update(
			$reservation,
		);
	}

	/**
	 * Release all active reservations for a workflow.
	 *
	 * DEADLOCK ORDERING: reservations are iterated in entitlement_id
	 * order (guaranteed by findActiveByFileId's ORDER BY), so entitlement
	 * locks are acquired in a deterministic order across concurrent
	 * workflow releases. Locks accumulate and are held until the caller's
	 * transaction commits — do not assume they release per iteration.
	 * Any other multi-entitlement locker must acquire in the same
	 * entitlement_id order.
	 *
	 * TRANSACTION: caller must have an open transaction; this loops
	 * releaseForSignRequest, which relies on it for FOR UPDATE.
	 *
	 * @throws Exception
	 */
	public function releaseForWorkflow(
		int $fileId,
	): void {

		$reservations = $this->reservationMapper
			->findActiveByFileId(
				$fileId
			);

		$this->logger->info(
			'[ENTITLEMENT RELEASE] Active reservations resolved.',
			[
				'fileId' => $fileId,
				'reservationCount' => count($reservations),
			],
		);

		foreach ($reservations as $reservation) {
			$this->logger->info(
				'[ENTITLEMENT RELEASE] Releasing reservation.',
				[
					'reservationId' => $reservation->getId(),
					'signRequestId' => $reservation->getSignRequestId(),
					'entitlementId' => $reservation->getEntitlementId(),
					'quantity' => $reservation->getQuantity(),
				],
			);
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

	/**
	 * Applies the effects of releasing a reservation.
	 *
	 * This method mutates the supplied entities but performs no
	 * persistence. Transaction ownership belongs to the caller.
	 *
	 * Used by:
	 * - releaseForSignRequest()
	 * - SigningSettlementService
	 */
	public function applyReservationRelease(
		EntitlementReservation $reservation,
		Entitlement $entitlement,
	): void {

		if ($reservation->getReleasedAt() !== null) {
			return;
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

		$reservation->setReleasedAt(
			(new \DateTimeImmutable(
				'now',
				new \DateTimeZone('UTC'),
			))->format(DATE_ATOM),
		);
	}
}
