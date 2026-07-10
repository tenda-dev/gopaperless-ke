<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipChangeDTO;

/**
 * Detects sponsorship changes between the incoming request
 * and the currently persisted sponsorship state.
 *
 * Responsibilities:
 * - Compare requested sponsorship state with persisted state.
 * - Identify signers with no previous persisted sponsorship state.
 * - Identify sponsorship transitions (self <-> requester).
 * - Produce SponsorshipChangeDTO objects.
 *
 * Source of truth:
 * - Previous sponsorship state is read from gopaperless_signer_sponsorships,
 *   NOT from SignRequest::getSponsorshipType(). The SignRequest column is
 *   inert; the sponsorship table is authoritative for who is sponsored and
 *   by whom. A signer with no row is self-sponsored ("absent = self").
 *
 * This service performs no validation or persistence.
 */
final class SponsorshipChangeDetectionService
{
	public function __construct(
		private SignerSponsorshipMapper $sponsorshipMapper,
	) {
	}

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

		/**
		 * Load previous sponsorship state for every persisted signer
		 * in a single batch, keyed by sign_request_id.
		 *
		 * Only sponsored signers have a row here; self-sponsored signers
		 * are absent from the map.
		 */
		$signRequestIds = array_map(
			static fn (SignRequestEntity $signRequest): int => $signRequest->getId(),
			$persistedSignRequests,
		);

		$sponsorshipMap = $this->sponsorshipMapper
			->findIndexedBySignRequestIds($signRequestIds);

		$changes = [];

		foreach ($incomingSigners as $incomingSigner) {

			$signRequestId = $incomingSigner->getSignRequestId();

			/**
			 * NEW SIGNER
			 *
			 * No persisted sign request yet; the id is minted during
			 * save. There is no previous sponsorship state to compare.
			 */
			if ($signRequestId === null) {
				$changes[] = new SponsorshipChangeDTO(
					signer: $incomingSigner,
					previousSponsorshipType: null,
					requestedSponsorshipType: $incomingSigner->getRequestedSponsorshipType(),
					isNewSigner: true,
				);

				continue;
			}

			/**
			 * EXISTING SIGNER
			 *
			 * Previous state comes from gopaperless_signer_sponsorships.
			 * Absent row => self-sponsored (the "absent = self" rule).
			 * the stored value is always a valid enum case
			 * by construction, so this cannot throw.
			 */
			$previousSponsorshipType =
				isset($sponsorshipMap[$signRequestId])
					? $sponsorshipMap[$signRequestId]->getSponsorshipTypeEnum()
					: SponsorshipType::SELF;

			$changes[] = new SponsorshipChangeDTO(
				signer: $incomingSigner,
				previousSponsorshipType: $previousSponsorshipType,
				requestedSponsorshipType: $incomingSigner->getRequestedSponsorshipType(),
				isNewSigner: false,
			);
		}

		return $changes;
	}
}
