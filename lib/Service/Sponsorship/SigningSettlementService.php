<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use OCA\Libresign\Service\Sponsorship\DTO\SettlementResolutionDTO;
use Psr\Log\LoggerInterface;

class SigningSettlementService
{
	public function __construct(
		private EntitlementMapper $entitlementMapper,
		private EntitlementReservationMapper $reservationMapper,
		private EntitlementReservationService $reservationService,
		private SignerSponsorshipService $sponsorshipService,
		private LoggerInterface $logger,
	) {}

	public function isSponsored(
		int $signRequestId,
	): bool {

		return $this->sponsorshipService
			->isRequesterSponsored($signRequestId);
	}

	public function getPayerUserId(
		int $signRequestId,
		string $defaultSignerUserId,
	): string {
		return $this->resolve(
			$signRequestId,
			$defaultSignerUserId,
		)->payerUserId;
	}

	public function resolve(
		int $signRequestId,
		string $defaultSignerUserId,
	): SettlementResolutionDTO {

		$sponsorship =
			$this->sponsorshipService
			->getBySignRequestId(
				$signRequestId
			);

		if ($sponsorship === null) {
			return new SettlementResolutionDTO(
				payerUserId: $defaultSignerUserId,
				sponsored: false,
			);
		}

		return new SettlementResolutionDTO(
			payerUserId: $sponsorship->getSponsorUserId(),
			sponsored: true,
		);
	}


	/**
	 * Settle a sponsored signing.
	 *
	 * Flow:
	 * - locate reservation
	 * - locate funded entitlement
	 * - consume entitlement
	 * - release reservation
	 *
	 * Returns the consumed entitlement.
	 */
	// TODO:
	// Eventually this service should become the single settlement
	// entry point for both sponsored and self-sponsored signing.
	public function settleSponsoredSigning(
		int $signRequestId,
	): Entitlement {

		$reservation =
			$this->reservationMapper
			->findActiveBySignRequestId(
				$signRequestId
			);

		if ($reservation === null) {
			throw new \RuntimeException(
				sprintf(
					'Active reservation not found for sign request %d',
					$signRequestId
				)
			);
		}

		$entitlement =
			$this->entitlementMapper
			->findById(
				$reservation->getEntitlementId()
			);

		if ($entitlement === null) {
			throw new \RuntimeException(
				sprintf(
					'Entitlement %d not found',
					$reservation->getEntitlementId()
				)
			);
		}

		/**
		 * Consume funded entitlement.
		 */
		$entitlement->consume();

		$this->entitlementMapper->update(
			$entitlement
		);

		/**
		 * Release reservation.
		 */
		$this->releaseReservation(
			$signRequestId
		);

		$this->logger->info(
			'[SIGNING SETTLEMENT] sponsored signing settled',
			[
				'signRequestId' => $signRequestId,
				'entitlementId' => $entitlement->getId(),
				'remainingUses' => $entitlement->getRemainingUses(),
			]
		);

		return $entitlement;
	}

	public function releaseReservation(
		int $signRequestId,
	): void {

		$this->reservationService
			->releaseForSignRequest(
				$signRequestId
			);

		$this->logger->info(
			'[SIGNING SETTLEMENT] reservation released',
			[
				'signRequestId' => $signRequestId,
			]
		);
	}
}
