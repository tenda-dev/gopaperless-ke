<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipChangeDTO;

/**
 * Detects sponsorship changes between the incoming request
 * and the currently persisted SignRequests.
 *
 * Responsibilities:
 * - Compare requested sponsorship state with persisted state.
 * - Identify newly sponsored signers.
 * - Identify sponsorship transitions.
 * - Produce SponsorshipChangeDTO objects.
 *
 * This service performs no validation or persistence.
 */
final class SponsorshipChangeDetectionService
{
	/**
	 * @param IncomingSignerDTO[] $incomingSigners
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return SponsorshipChangeDTO[]
	 */
	public function detect(
		array $incomingSigners,
		array $persistedSignRequests,
	): array {

		$persistedById = [];

		foreach ($persistedSignRequests as $signRequest) {
			$persistedById[$signRequest->getId()] = $signRequest;
		}

		$changes = [];

		foreach ($incomingSigners as $incomingSigner) {

			$signRequestId = $incomingSigner->getSignRequestId();

			if ($signRequestId === null) {
				$changes[] = new SponsorshipChangeDTO(
					signer: $incomingSigner,
					previousSponsorshipType: null,
					requestedSponsorshipType: $incomingSigner->getRequestedSponsorshipType(),
					isNewSigner: true,
				);

				continue;
			}

			$persisted = $persistedById[$signRequestId] ?? null;

			if ($persisted === null) {
				throw new \LogicException(sprintf(
					'Persisted SignRequest %d not found.',
					$signRequestId,
				));
			}

			$changes[] = new SponsorshipChangeDTO(
				signer: $incomingSigner,
				previousSponsorshipType: SponsorshipType::from(
					$persisted->getSponsorshipType(),
				),
				requestedSponsorshipType: $incomingSigner->getRequestedSponsorshipType(),
				isNewSigner: false,
			);
		}

		return $changes;
	}
}
