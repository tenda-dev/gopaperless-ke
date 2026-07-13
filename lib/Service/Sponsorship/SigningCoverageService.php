<?php

declare(strict_types=1);

/**
 * TODO (Architecture):
 * Move this service to the Signing namespace once the signing
 * settlement implementation has stabilized.
 */

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Sponsorship\DTO\SigningCoverageResolutionDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSponsorshipDTO;
use Psr\Log\LoggerInterface;

/**
 * Resolves whether a signer has sufficient financial coverage
 * to complete a signing.
 *
 * This service is the financial gatekeeper for the signing
 * workflow and is used by the signing paywall.
 *
 * No state is modified by this service.
 *
 * Resolution order:
 *
 * 1. Existing requester sponsorship.
 *    A reservation already exists and therefore signing
 *    is immediately authorised.
 *
 * 2. Signer's own entitlement.
 *    The signer funds their own signing credits.
 *
 * 3. No coverage.
 *    Payment is required before signing may continue.
 *
 * This service performs no persistence.
 */
final class SigningCoverageService
{
	public function __construct(
		private SignerSponsorshipService $sponsorshipService,
		private EntitlementService $entitlementService,
		private EntitlementReservationMapper $entitlementReservationMapper,
		private EntitlementMapper $entitlementMapper,
		private LoggerInterface $logger,
	) {}


	/**
	 * Resolves the sponsorship state for a signing.
	 *
	 * This method is intended for presentation concerns only, such as
	 * displaying sponsorship badges or payer information in the signing UI.
	 *
	 */
	public function resolveSigningSponsorship(
		int $signRequestId,
	): SigningSponsorshipDTO {

		$sponsorship = $this->sponsorshipService
			->findSignRequestSponsorship($signRequestId);

		if ($sponsorship === null) {
			return new SigningSponsorshipDTO(
				type: SponsorshipType::SELF,
			);
		}

		return new SigningSponsorshipDTO(
			type: SponsorshipType::REQUESTER,
			sponsorUserId: $sponsorship->getSponsorUserId(),
		);
	}

	public function resolveSigningCoverage(
		int $signRequestId,
		string $signerUserId,
		string $productCode,
	): SigningCoverageResolutionDTO {

		$sponsorship = $this->sponsorshipService->findSignRequestSponsorship(
			$signRequestId,
		);

		/**
		 * Requester sponsored.
		 *
		 * Capacity has already been reserved.
		 */
		if ($sponsorship !== null) {

			$reservation = $this->entitlementReservationMapper
				->findActiveBySignRequestId($signRequestId);

			if ($reservation === null) {
				$this->logger->warning(
					'[SigningCoverageService] Active reservation not found for requester-sponsored signing.',
					[
						'signRequestId' => $signRequestId,
						'sponsorUserId' => $sponsorship->getSponsorUserId(),
					],
				);
			} else {
				$entitlement = $this->entitlementMapper
					->findById($reservation->getEntitlementId());

				if ($entitlement === null) {
					$this->logger->warning(
						'[SigningCoverageService] Reserved entitlement not found.',
						[
							'signRequestId' => $signRequestId,
							'entitlementId' => $reservation->getEntitlementId(),
						],
					);
				} else {
					return new SigningCoverageResolutionDTO(
						allowed: true,
						sponsored: true,
						sponsorUserId: $sponsorship->getSponsorUserId(),
					);
				}
			}
		}

		/**
		 * Self-sponsored.
		 */
		if (
			$this->entitlementService->canUse(
				$signerUserId,
				$productCode,
			)
		) {
			$this->logger->info(
				'[SigningCoverageService] Signing covered by signer entitlement.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $signerUserId,
					'productCode' => $productCode,
				],
			);
			return new SigningCoverageResolutionDTO(
				allowed: true,
				sponsored: false,
				sponsorUserId: $signerUserId,
			);
		}


		$this->logger->info(
			'[SigningCoverageService] No signing coverage available.',
			[
				'signRequestId' => $signRequestId,
				'signerUserId' => $signerUserId,
				'productCode' => $productCode,
			],
		);

		/**
		 * No funding source exists.
		 */
		return new SigningCoverageResolutionDTO(
			allowed: false,
			sponsored: false,
			sponsorUserId: null,
		);
	}
}
