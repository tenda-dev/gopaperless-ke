<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Service\Sponsorship\DTO\PersistedSignerSponsorshipDTO;
/**
 * Coordinates the sponsorship persistence workflow.
 *
 * Responsibilities:
 * - Orchestrate sponsorship persistence.
 * - Build the sponsorship workflow context.
 * - Produce a reconciliation plan.
 * - Execute the synchronisation plan.
 *
 * This service intentionally owns orchestration only.
 *
 * Business rules remain inside the specialised services.
 */
final class SponsorshipWorkflowService
{
	public function __construct(
		private SponsorshipChangeDetectionService $changeDetectionService,
		private SponsorshipReconciliationService $reconciliationService,
		private SponsorshipReservationSyncService $syncService,
		private SponsorshipContextBuilderService $contextBuilder,
	) {}

	/**
	 * Synchronises sponsorship state after SignRequests have been persisted.
	 *
	 * The workflow:
	 * - Build the incoming sponsorship context.
	 * - Detect sponsorship changes.
	 * - Produce a reconciliation plan.
	 * - Execute the synchronisation plan.
	 * - Project the persisted sponsorship state for API consumption.
	 *
	 * @param array $incomingSigners Raw FE signer payload.
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @return PersistedSignerSponsorshipDTO[]
	 */
	public function persist(
		File $file,
		string $requesterUserId,
		string $productCode,
		array $incomingSigners,
		array $persistedSignRequests,
	): array {

		// No sponsorship synchronisation is performed for draft files.
		if ($file->getStatusEnum() === FileStatus::DRAFT) {
			return $this->contextBuilder
				->buildDraftSponsoredSigners(
					$persistedSignRequests,
				);
		}

		$incomingSignerDtos = $this->contextBuilder
			->buildIncomingSigners($incomingSigners);

		$incomingSignerDtos = $this->contextBuilder
			->associateToSignRequests(
				signers: $incomingSignerDtos,
				persistedSignRequests: $persistedSignRequests,
			);

		$changes = $this->changeDetectionService->detect(
			$file,
			$incomingSignerDtos,
			$persistedSignRequests,
		);

		$plan = $this->reconciliationService->reconcile(
			$changes,
		);

		$this->syncService->sync(
			fileId: $file->getId(),
			requesterUserId: $requesterUserId,
			productCode: $productCode,
			plan: $plan,
		);

		return $this->contextBuilder
			->buildPersistedSponsoredSigners(
				$persistedSignRequests,
			);
	}

	public function releaseSigner(int $signRequestId): void
	{
		$this->syncService->releaseSigner(
			$signRequestId,
		);
	}

	public function releaseWorkflow(int $fileId): void
	{
		$this->syncService->releaseWorkflow(
			$fileId
		);
	}
}
