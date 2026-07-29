<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCA\Libresign\Service\Sponsorship\DTO\PersistedSignerSponsorshipDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipDTO;

/**
 * Builds sponsorship domain projections used throughout the sponsorship
 * workflow.
 *
 * Responsibilities:
 * - Project incoming FE payload into domain DTOs.
 * - Associate incoming signers with persisted SignRequests.
 * - Project persisted SignRequests into API response DTOs.
 *
 * This service owns context construction only.
 * It performs no validation, reconciliation or persistence.
 */
final class SponsorshipContextBuilderService {
	public function __construct(
		private IdentifyMethodService $identifyMethodService,
		private SignerSponsorshipService $signerSponsorshipService,
	) {
	}

	public function buildSignersWithSponsorshipContext(
		File $file,
		array $signRequests,
	): array {
		return $file->getStatusEnum() === FileStatus::DRAFT
			? $this->buildDraftSponsoredSigners($signRequests)
			: $this->buildPersistedSponsoredSigners($signRequests);
	}

	/**
	 * Projects persisted draft SignRequests into API response DTOs.
	 *
	 * While a file remains in the draft state, sponsorship selections are
	 * stored directly on the SignRequest. No SignerSponsorship records have
	 * been created yet, so the SignRequest acts as the temporary source of
	 * sponsorship truth.
	 *
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return PersistedSignerSponsorshipDTO[]
	 */
	public function buildDraftSponsoredSigners(
		array $persistedSignRequests,
	): array {

		$dtos = [];

		foreach ($persistedSignRequests as $signRequest) {
			$dtos[] = new PersistedSignerSponsorshipDTO(
				signRequest: $signRequest,
				sponsorship: SponsorshipDTO::fromDraftSignRequest(
					$signRequest,
				),
			);
		}

		return $dtos;
	}

	/**
	 * Projects the incoming FE signer payload into domain DTOs.
	 *
	 * Incoming signers are treated as transient domain objects and are not
	 * associated with persistence at this stage. Association with existing
	 * SignRequests is performed separately.
	 *
	 * @param array $incomingSigners
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function buildIncomingSigners(
		array $incomingSigners,
	): array {

		$dtos = [];

		foreach ($incomingSigners as $signer) {

			foreach ($signer['identifyMethods'] as $identifyMethod) {

				$dtos[] = IncomingSignerDTO::fromRequest(
					$signer,
					$identifyMethod,
				);
			}
		}

		return $dtos;
	}

	/**
	 * Resolves persisted SignRequests for the supplied incoming signers.
	 *
	 * Matching is performed using signer identity (identify method +
	 * identifier value). Existing signers are hydrated with their persisted
	 * SignRequest id while newly added signers intentionally retain a null id.
	 *
	 * @param IncomingSignerDTO[] $signers
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function associateToSignRequests(
		array $signers,
		array $persistedSignRequests,
	): array {

		$lookup = $this->buildSignRequestLookup(
			$persistedSignRequests,
		);

		foreach ($signers as $signer) {
			$this->associatePersistedSignRequest(
				$signer,
				$lookup,
			);
		}

		return $signers;
	}

	/**
	 * Projects persisted SignRequests into API response DTOs.
	 *
	 * Each persisted signer is enriched with its current sponsorship state,
	 * producing the canonical response model returned by the sponsorship
	 * workflow.
	 *
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return PersistedSignerSponsorshipDTO[]
	 */
	public function buildPersistedSponsoredSigners(
		array $persistedSignRequests,
	): array {

		$dtos = [];

		$lookup = $this->buildSponsorshipLookup(
			$persistedSignRequests,
		);

		foreach ($persistedSignRequests as $signRequest) {
			$dtos[] = new PersistedSignerSponsorshipDTO(
				signRequest: $signRequest,
				sponsorship: SponsorshipDTO::fromEntity(
					$lookup[$signRequest->getId()] ?? null,
				),
			);
		}

		return $dtos;
	}

	/**
	 * Builds an O(1) lookup of persisted SignRequests keyed by signer identity.
	 *
	 * The lookup allows incoming signers to be associated with persisted
	 * SignRequests without repeatedly traversing the persisted collection.
	 *
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return array<string, SignRequestEntity>
	 */
	private function buildSignRequestLookup(
		array $persistedSignRequests,
	): array {

		$lookup = [];

		foreach ($persistedSignRequests as $signRequest) {

			$identifyMethods
				= $this->identifyMethodService
					->getIdentifyMethodsFromSignRequestId(
						$signRequest->getId(),
					);

			foreach ($identifyMethods as $methods) {
				foreach ($methods as $method) {

					$entity = $method->getEntity();

					$key = $this->buildIdentityKey(
						$entity->getIdentifierKey(),
						$entity->getIdentifierValue(),
					);

					$lookup[$key] = $signRequest;
				}
			}
		}

		return $lookup;
	}

	/**
	 * Associates a persisted SignRequest with an incoming signer.
	 *
	 * If no matching SignRequest exists the signer is considered new and
	 * intentionally retains a null signRequestId.
	 *
	 * @param array<string, SignRequestEntity> $lookup
	 */
	private function associatePersistedSignRequest(
		IncomingSignerDTO $signer,
		array $lookup,
	): void {

		$key = $this->buildIdentityKey(
			$signer->getIdentifierKey(),
			$signer->getIdentifierValue(),
		);

		if (!isset($lookup[$key])) {
			return;
		}

		$signer->setSignRequestId(
			$lookup[$key]->getId(),
		);
	}

	/**
	 * Builds a stable identity key used to correlate incoming signers with
	 * persisted SignRequests.
	 */
	private function buildIdentityKey(
		string $identifierKey,
		string $identifierValue,
	): string {
		return sprintf(
			'%s:%s',
			$identifierKey,
			$identifierValue,
		);
	}

	/**
	 * Builds an O(1) lookup of sponsorship state keyed by SignRequest id.
	 *
	 * This allows persisted signers to be enriched with sponsorship
	 * information without issuing individual database lookups.
	 *
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return array<int, SignerSponsorship>
	 */
	private function buildSponsorshipLookup(
		array $persistedSignRequests,
	): array {

		$ids = array_map(
			static fn (SignRequestEntity $sr): int => $sr->getId(),
			$persistedSignRequests,
		);

		return $this->signerSponsorshipService
			->getIndexedBySignRequestIds(
				$ids,
			);
	}
}
