<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SponsorshipReconciliationResultDTO;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Executes the sponsorship synchronisation plan.
 *
 * Responsibilities:
 * - Own the transaction boundary.
 * - Execute queued sponsorship releases.
 * - Execute queued sponsorship reservations.
 * - Keep entitlement reservations and sponsorship records consistent.
 *
 * This service performs persistence only.
 * It performs no validation, change detection or reconciliation.
 */
final class SponsorshipReservationSyncService
{
	public function __construct(
		private EntitlementReservationService $reservationService,
		private SignerSponsorshipService $sponsorshipService,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {}

	public function sync(
		int $fileId,
		string $requesterUserId,
		string $productCode,
		SponsorshipReconciliationResultDTO $plan,
	): void {

		if (!$plan->hasChanges()) {
			return;
		}

		$this->db->beginTransaction();

		try {
			foreach ($plan->getQueuedReleases() as $signer) {
				$this->releaseRequesterSponsorship($signer);
			}

			foreach ($plan->getQueuedReservations() as $signer) {
				$this->createRequesterSponsorship(
					$fileId,
					$requesterUserId,
					$productCode,
					$signer,
				);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();

			$this->logger->error(
				'[SPONSORSHIP SYNC] failed',
				[
					'exception' => $e,
				]
			);

			throw $e;
		}
	}

	public function releaseSigner(int $signRequestId): void
	{
		$this->db->beginTransaction();

		try {
			$this->reservationService
				->releaseForSignRequest($signRequestId);

			$this->sponsorshipService
				->deleteBySignRequestId($signRequestId);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function releaseWorkflow(int $fileId): void
	{
		$this->db->beginTransaction();

		try {
			$this->reservationService
				->releaseForWorkflow($fileId);

			$this->sponsorshipService
				->deleteByFileId($fileId);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function releaseRequesterSponsorship(
		IncomingSignerDTO $signer,
	): void {
		$signRequestId = $this->requireSignRequestId($signer);

		$this->reservationService
			->releaseForSignRequest($signRequestId);

		$this->sponsorshipService
			->deleteBySignRequestId($signRequestId);

		$this->logger->info(
			'[SPONSORSHIP SYNC] sponsorship released',
			[
				'signRequestId' => $signRequestId,
			]
		);
	}

	private function createRequesterSponsorship(
		int $fileId,
		string $requesterUserId,
		string $productCode,
		IncomingSignerDTO $signer,
	): void {
		$signRequestId = $this->requireSignRequestId($signer);

		/**
		 * Defensive idempotency.
		 *
		 * Reconciliation should already prevent duplicate work,
		 * but this avoids duplicate reservations if sync()
		 * is accidentally executed twice.
		 */
		if ($this->sponsorshipService->isRequesterSponsored($signRequestId)) {
			$this->logger->debug(
				'[SPONSORSHIP SYNC] skipped existing sponsorship',
				[
					'signRequestId' => $signRequestId,
				]
			);
			return;
		}

		$this->reservationService
			->reserveForWorkflow(
				$requesterUserId,
				$productCode,
				$fileId,
				$signRequestId,
				1,
			);

		$this->sponsorshipService
			->create(
				$fileId,
				$signRequestId,
				$requesterUserId,
				SponsorshipType::REQUESTER,
			);

		$this->logger->info(
			'[SPONSORSHIP SYNC] sponsorship created',
			[
				'signRequestId' => $signRequestId,
			]
		);
	}

	private function requireSignRequestId(
		IncomingSignerDTO $signer,
	): int {
		$signRequestId = $signer->getSignRequestId();

		if ($signRequestId === null) {
			throw new \LogicException(
				'Reconciliation produced a signer without a persisted SignRequest ID.'
			);
		}

		return $signRequestId;
	}
}
