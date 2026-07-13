<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipChangeDTO;

/**
 * Detects sponsorship changes that require entitlement reconciliation.
 *
 * Sponsorship evolves through two distinct lifecycle stages:
 *
 * Draft
 * Sponsorship exists only as workflow configuration stored on
 * SignRequest::getSponsorshipType(). No entitlement reservations or
 * SignerSponsorship rows exist yet.
 *
 * Active Workflow
 * Once the workflow is published (ABLE_TO_SIGN), sponsorship is
 * materialised into gopaperless_signer_sponsorships. From that point
 * onwards the sponsorship table becomes the authoritative source.
 *
 * This service hides those lifecycle differences behind a single API,
 * always returning SponsorshipChangeDTO objects for downstream
 * validation.
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
		File $file,
		array $incomingSigners,
		array $persistedSignRequests,
	): array {

		/**
		 * Publishing a draft is fundamentally different from editing an
		 * already active workflow.
		 *
		 * During publication there are no persisted sponsorship rows to
		 * compare against, so we instead determine which draft selections
		 * must become sponsorship records.
		 */
		if ($file->getStatusEnum() === FileStatus::DRAFT) {
			return $this->detectDraftPublication(
				$incomingSigners,
				$persistedSignRequests,
			);
		}

		/**
		 * Active workflows already have persisted sponsorship state.
		 * Compare the incoming request against the materialised workflow.
		 */
		return $this->detectWorkflowChanges(
			$incomingSigners,
			$persistedSignRequests,
		);
	}

	/**
	 * Detect sponsorship that must be materialised while publishing a
	 * draft workflow.
	 *
	 * Although the incoming request already contains sponsorship
	 * selections, we intentionally reconcile them with the persisted
	 * SignRequest entities. SignRequest is the backend-owned staging area
	 * for draft sponsorship configuration and remains the source of truth
	 * until publication succeeds.
	 *
	 * Every requester-sponsored draft signer is treated as transitioning
	 * from SELF → REQUESTER because no SignerSponsorship rows exist yet.
	 *
	 * @param IncomingSignerDTO[] $incomingSigners
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return SponsorshipChangeDTO[]
	 */
	private function detectDraftPublication(
		array $incomingSigners,
		array $persistedSignRequests,
	): array {

		$persistedLookup = [];

		foreach ($persistedSignRequests as $signRequest) {
			$persistedLookup[$signRequest->getId()] = $signRequest;
		}

		$changes = [];

		foreach ($incomingSigners as $incomingSigner) {

			$signRequestId = $incomingSigner->getSignRequestId();

			/**
			 * Brand new signer added immediately before publication.
			 *
			 * There is no persisted draft state yet, so compare directly
			 * against the incoming request.
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

			$persistedSignRequest = $persistedLookup[$signRequestId] ?? null;

			/**
			 * Draft sponsorship has not yet been materialised.
			 *
			 * Treat every requester-sponsored draft signer as a transition
			 * from SELF → REQUESTER so downstream validation determines
			 * whether sufficient entitlement exists before publication.
			 */
			$changes[] = new SponsorshipChangeDTO(
				signer: $incomingSigner,
				previousSponsorshipType: SponsorshipType::SELF,
				requestedSponsorshipType:
					$persistedSignRequest?->getSponsorshipTypeEnum()
					?? $incomingSigner->getRequestedSponsorshipType(),
				isNewSigner: false,
			);
		}

		return $changes;
	}

	/**
	 * Detect sponsorship changes within an already active workflow.
	 *
	 * The authoritative workflow state now lives in
	 * gopaperless_signer_sponsorships. Incoming sponsorship selections are
	 * compared against those persisted records to determine whether
	 * additional reservations are required.
	 *
	 * @param IncomingSignerDTO[] $incomingSigners
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return SponsorshipChangeDTO[]
	 */
	private function detectWorkflowChanges(
		array $incomingSigners,
		array $persistedSignRequests,
	): array {

		$previousState = $this->buildPersistedSponsorshipLookup(
			$persistedSignRequests,
		);

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

			$changes[] = new SponsorshipChangeDTO(
				signer: $incomingSigner,
				previousSponsorshipType:
					$previousState[$signRequestId]
					?? SponsorshipType::SELF,
				requestedSponsorshipType:
					$incomingSigner->getRequestedSponsorshipType(),
				isNewSigner: false,
			);
		}

		return $changes;
	}

	/**
	 * Builds the persisted sponsorship state keyed by SignRequest id.
	 *
	 * Only active workflows create SignerSponsorship rows. Any missing
	 * entry is therefore interpreted as SELF-sponsored.
	 *
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return array<int,SponsorshipType>
	 */
	private function buildPersistedSponsorshipLookup(
		array $persistedSignRequests,
	): array {

		$signRequestIds = array_map(
			static fn (SignRequestEntity $signRequest): int =>
				$signRequest->getId(),
			$persistedSignRequests,
		);

		$sponsorships = $this->sponsorshipMapper
			->findIndexedBySignRequestIds($signRequestIds);

		$lookup = [];

		foreach ($sponsorships as $signRequestId => $sponsorship) {
			$lookup[$signRequestId] =
				$sponsorship->getSponsorshipTypeEnum();
		}

		return $lookup;
	}
}
