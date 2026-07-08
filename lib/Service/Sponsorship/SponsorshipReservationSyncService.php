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
 * Executes sponsorship synchronisation.
 *
 * Responsibilities:
 * - Own the transaction boundary for sponsorship synchronisation.
 * - Reserve entitlement capacity.
 * - Release entitlement capacity.
 * - Create requester sponsorships.
 * - Remove requester sponsorships.
 *
 * This service executes the reconciliation plan.
 * It performs no reconciliation or validation.
 */
final class SponsorshipReservationSyncService
{
	public function __construct(
		private EntitlementReservationService $reservationService,
		private SignerSponsorshipService $sponsorshipService,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

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
			foreach ($plan->getRequiresRelease() as $signer) {
				$this->releaseReservation($signer);
			}

			foreach ($plan->getRequiresReservation() as $signer) {
				$this->createReservation(
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

	private function releaseReservation(
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

	private function createReservation(
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
