<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipChangeDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipReconciliationResultDTO;

final class SponsorshipReconciliationService
{
	/**
	 * Converts sponsorship changes into an executable
	 * synchronisation plan.
	 *
	 * Responsibilities:
	 * - Translate sponsorship changes into reservation actions.
	 * - Translate sponsorship changes into release actions.
	 * - Produce an execution plan for synchronisation.
	 *
	 * This service performs no persistence.
	 */
	public function reconcile(
		array $changes,
	): SponsorshipReconciliationResultDTO {

		$plan = new SponsorshipReconciliationResultDTO();

		foreach ($changes as $change) {

			/**
			 * SELF -> REQUESTER
			 *
			 * A new sponsorship reservation must be created.
			 */
			if ($change->requiresReservation()) {
				$plan->addRequiresReservation(
					$change->signer(),
				);

				continue;
			}

			/**
			 * REQUESTER -> SELF
			 *
			 * Existing sponsorship should be released.
			 */
			if ($change->requiresRelease()) {
				$plan->addRequiresRelease(
					$change->signer(),
				);
			}
		}

		return $plan;
	}
}
