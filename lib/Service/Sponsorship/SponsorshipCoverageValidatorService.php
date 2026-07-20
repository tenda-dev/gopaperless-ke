<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipChangeDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipCoverageValidationResultDTO;

final class SponsorshipCoverageValidatorService {
	public function __construct(
		private EntitlementService $entitlementService,
	) {
	}

	/**
	 * Validates whether the requester has sufficient entitlement
	 * capacity to fund the supplied sponsorship changes.
	 *
	 * This service performs no persistence.
	 *
	 * @param SponsorshipChangeDTO[] $changes
	 */
	public function validate(
		string $productCode,
		string $requesterUserId,
		array $changes,
	): SponsorshipCoverageValidationResultDTO {

		$requiredCredits = 0;
		$blockingSignRequestIds = [];

		foreach ($changes as $change) {

			if (!$change->requiresReservation()) {
				continue;
			}

			++$requiredCredits;

			if ($change->signer()->hasPersistedSignRequest()) {
				$blockingSignRequestIds[]
					= $change->signer()->getSignRequestId();
			}
		}

		/**
		 * Nothing new requires sponsorship.
		 */
		if ($requiredCredits === 0) {
			return new SponsorshipCoverageValidationResultDTO(
				allowed: true,
				requiredCredits: 0,
				availableCredits: 0,
				missingCredits: 0,
			);
		}

		$result = $this->entitlementService->getAvailableCredits($requesterUserId, $productCode);

		$availableCredits = $result['remainingUses'] ?? 0;

		if ($availableCredits >= $requiredCredits) {
			return new SponsorshipCoverageValidationResultDTO(
				allowed: true,
				requiredCredits: $requiredCredits,
				availableCredits: $availableCredits,
				missingCredits: 0,
			);
		}

		return new SponsorshipCoverageValidationResultDTO(
			allowed: false,
			requiredCredits: $requiredCredits,
			availableCredits: $availableCredits,
			missingCredits: $requiredCredits - $availableCredits,
			blockingSignRequestIds: $blockingSignRequestIds,
		);
	}
}
