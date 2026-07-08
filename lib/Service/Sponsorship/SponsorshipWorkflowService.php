<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;

/**
 * Coordinates the sponsorship persistence workflow.
 *
 * Responsibilities:
 * - Build the sponsorship workflow context from the incoming request.
 * - Associate incoming signers with persisted SignRequests.
 * - Detect sponsorship changes.
 * - Produce an execution plan.
 * - Execute the synchronization plan.
 *
 * This service intentionally owns orchestration only.
 *
 * Business rules remain inside the specialised services.
 */
final class SponsorshipWorkflowService
{
	public function __construct(
		private IdentifyMethodService $identifyMethodService,
		private SponsorshipChangeDetectionService $changeDetectionService,
		private SponsorshipReconciliationService $reconciliationService,
		private SponsorshipReservationSyncService $syncService,
	) {
	}

	/**
	 * Synchronise sponsorships after SignRequests have been persisted.
	 *
	 * @param array $incomingSigners Raw FE signers.
	 * @param SignRequestEntity[] $persistedSignRequests
	 */
	public function persist(
		File $file,
		string $requesterUserId,
		string $productCode,
		array $incomingSigners,
		array $persistedSignRequests,
	): void {
		$incomingSignerDtos = $this->buildIncomingSigners(
			$incomingSigners,
			$persistedSignRequests,
		);

		$changes = $this->changeDetectionService->detect(
			$incomingSignerDtos,
			$persistedSignRequests,
		);

		$executionPlan = $this->reconciliationService->reconcile(
			$changes,
		);

		$this->syncService->sync(
			$file->getId(),
			$requesterUserId,
			$productCode,
			$executionPlan,
		);
	}

	/**
	 * Builds the sponsorship workflow context from the incoming FE payload.
	 *
	 * Each incoming signer is converted into an IncomingSignerDTO and
	 * associated with its persisted SignRequest.
	 *
	 * @param array $incomingSigners
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return IncomingSignerDTO[]
	 */
	private function buildIncomingSigners(
		array $incomingSigners,
		array $persistedSignRequests,
	): array {
		$lookup = $this->buildSignRequestLookup(
			$persistedSignRequests,
		);

		$dtos = [];

		foreach ($incomingSigners as $signer) {
			foreach ($signer['identifyMethods'] as $identifyMethod) {
				$dto = new IncomingSignerDTO(
					identifierKey: $identifyMethod['method'],
					identifierValue: $identifyMethod['value'],
					displayName: $signer['displayName'] ?? '',
					requestedSponsorshipType: SponsorshipType::tryFrom(
						$signer['sponsorshipType']
					) ?? SponsorshipType::SELF,
				);

				$this->associatePersistedSignRequest(
					$dto,
					$lookup,
				);

				$dtos[] = $dto;
			}
		}

		return $dtos;
	}

	/**
	 * Builds a lookup table for persisted SignRequests keyed by signer identity.
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
			$identifyMethods =
				$this->identifyMethodService
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
	 * Resolves and associates the persisted SignRequest
	 * for the supplied incoming signer.
	 *
	 * @param array<string, SignRequestEntity> $lookup
	 *
	 * @throws \LogicException
	 *   If no persisted SignRequest matches the incoming signer.
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
			throw new \LogicException(sprintf(
				'Unable to associate persisted SignRequest for signer "%s".',
				$signer->getDisplayName(),
			));
		}

		$signer->setSignRequestId(
			$lookup[$key]->getId(),
		);
	}

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
}
