<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * TODO (Architecture):
 * Move this service to the Signing namespace once the signing
 * settlement implementation has stabilised.
 */
namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Exception\EntitlementAlreadyConsumedException;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Exception\StaleEntitlementException;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSettlementContextDTO;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Performs financial settlement after a successful signing.
 *
 * Responsibilities
 *
 * - Determine the settlement path (self-sponsored vs requester-sponsored).
 * - Execute entitlement state transitions atomically.
 * - Persist settlement metadata for idempotency and auditing.
 *
 * This service intentionally performs no business validation. Validation,
 * entity resolution and row locking are delegated to
 * SigningSettlementValidationService before any state mutation occurs.
 *
 * Transaction
 *
 * Settlement modifies multiple persistence models (sign request,
 * entitlement and, for requester-sponsored signings, entitlement
 * reservations). All mutations execute inside a single database
 * transaction to guarantee atomicity.
 *
 * Idempotency
 *
 * Settlement is idempotent. The validation pipeline rejects sign requests
 * that have already been settled by inspecting the persisted
 * entitlement_consumed metadata before any mutation occurs.
 */
final class SigningSettlementService {
	private const LOG_PREFIX = '[EntitlementSettlement][SigningSettlementService]';

	/**
	 * Safety limit for stale self-sponsored retries. Normal exhaustion is bounded
	 * by the number of available entitlement candidates.
	 */
	private const MAX_SELF_SPONSORED_ATTEMPTS = 25;

	public function __construct(
		private IDBConnection $db,
		private SigningSettlementValidationService $validationService,
		private EntitlementMapper $entitlementMapper,
		private EntitlementReservationMapper $reservationMapper,
		private EntitlementReservationService $reservationService,
		private SignRequestMapper $signRequestMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Finalises entitlement accounting after a successful signing.
	 *
	 * The validation pipeline determines the settlement path and enriches the
	 * context with every entity required for settlement. This service only
	 * performs transactional state transitions.
	 */
	public function settle(
		int $signRequestId,
		string $signerUserId,
		string $productCode,
		SettlementSource $source,
	): Entitlement {
		$excludedEntitlementIds = [];

		for ($attempt = 1; $attempt <= self::MAX_SELF_SPONSORED_ATTEMPTS; $attempt++) {
			$this->db->beginTransaction();
			$entitlement = null;

			try {
				$this->logger->info(
					self::LOG_PREFIX . ' Starting signing settlement.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'productCode' => $productCode,
						'attempt' => $attempt,
					],
				);

				$context = $this->validationService->validateCommon(
					signRequestId: $signRequestId,
					signerUserId: $signerUserId,
					productCode: $productCode,
				);

				$settlementPath = $context->sponsorship !== null
					? 'requester_sponsored'
					: 'self_sponsored';

				$this->logger->debug(
					self::LOG_PREFIX . ' Settlement path selected.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'settlementPath' => $settlementPath,
						'attempt' => $attempt,
					],
				);

				if ($context->sponsorship !== null) {
					$context = $this->validationService
						->validateRequesterSponsored($context);

					$entitlement = $this->settleRequesterSponsored($context, $source);
				} else {
					// Re-select on each attempt so previously stale candidates can become
					// eligible again if concurrent activity restores their capacity.
					$context = $this->validationService
						->validateSelfSponsored($context, $excludedEntitlementIds);

					$entitlement = $this->settleSelfSponsored($context, $source);
				}

				$this->db->commit();

				// Log success only after commit, when the settlement is durable.
				$this->logger->info(
					self::LOG_PREFIX . ' Signing settlement completed successfully.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'productCode' => $productCode,
						'settlementPath' => $settlementPath,
						'entitlementId' => $entitlement->getId(),
						'remainingUses' => $entitlement->getRemainingUses(),
						'outcome' => 'committed',
					],
				);

				return $entitlement;
			} catch (EntitlementAlreadyConsumedException $e) {
				// Idempotent no-op: Settlement was already committed. Roll back this attempt and treat
				// the duplicate as an expected idempotent outcome.
				$this->db->rollBack();

				$this->logger->info(
					self::LOG_PREFIX . ' Settlement skipped: sign request was already settled.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'productCode' => $productCode,
						'outcome' => 'skipped_already_consumed',
					],
				);

				throw $e;
			} catch (StaleEntitlementException $e) {
				// Roll back before retrying to release the sign request and entitlement
				// locks. Holding locks across retries could create a deadlock with the
				// requester-sponsored settlement path.
				$this->db->rollBack();

				$this->logger->warning(
					self::LOG_PREFIX . ' Self-sponsored entitlement was stale under lock; retrying with it excluded.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'staleEntitlementId' => $e->getEntitlementId(),
						'attempt' => $attempt,
						'outcome' => 'stale_retry',
					],
				);

				$excludedEntitlementIds[] = $e->getEntitlementId();
				continue;
			} catch (\Throwable $e) {
				$this->db->rollBack();

				$this->logger->error(
					self::LOG_PREFIX . ' Signing settlement failed.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $signerUserId,
						'productCode' => $productCode,
						'attempt' => $attempt,
						'exceptionClass' => get_class($e),
						'exception' => $e,
						'outcome' => 'failed',
					],
				);

				throw $e;
			}
		}

		throw new InsufficientEntitlementException(sprintf(
			'Signing settlement exhausted %d attempt(s) on stale self-sponsored entitlements for sign request %d.',
			self::MAX_SELF_SPONSORED_ATTEMPTS,
			$signRequestId,
		));
	}

	/**
	 * Finalises settlement for a requester-sponsored signing.
	 *
	 * The entitlement backing this signing was previously reserved during
	 * workflow sponsorship.
	 *
	 * Reservation capacity is released before consumption so that the
	 * entitlement returns to a consistent state before permanent usage is
	 * recorded. Both mutations occur inside the same transaction and are
	 * therefore atomic.
	 *
	 */
	private function settleRequesterSponsored(
		SigningSettlementContextDTO $context,
		SettlementSource $source,
	): Entitlement {
		$reservation = $context->reservation;
		$entitlement = $context->entitlement;

		$this->logger->debug(
			self::LOG_PREFIX . ' Releasing reservation ahead of requester-sponsored consumption.',
			[
				'signRequestId' => $context->signRequest->getId(),
				'reservationId' => $reservation->getId(),
				'entitlementId' => $entitlement->getId(),
				'settlementPath' => 'requester_sponsored',
			],
		);

		$this->reservationService->applyReservationRelease(
			$reservation,
			$entitlement,
		);

		$entitlement->consume(
			$reservation->getQuantity(),
		);

		$this->entitlementMapper->update($entitlement);
		$this->reservationMapper->update($reservation);

		$this->markEntitlementConsumed($context, 'requester_sponsored', $source);

		return $entitlement;
	}

	/**
	 * Finalises settlement for a self-sponsored signing.
	 *
	 * The signer consumes one of their own available entitlement uses.
	 *
	 */
	private function settleSelfSponsored(
		SigningSettlementContextDTO $context,
		SettlementSource $source,
	): Entitlement {
		$entitlement = $context->entitlement;

		$entitlement->consume();

		$this->entitlementMapper->update($entitlement);

		$this->markEntitlementConsumed($context, 'self_sponsored', $source);

		return $entitlement;
	}

	/**
	 * Marks the sign request as financially settled.
	 *
	 * Settlement metadata provides:
	 *
	 * - Idempotency, preventing duplicate entitlement consumption.
	 * - Auditability by recording the product and entitlement used.
	 *
	 * This method is invoked only after every settlement state transition has
	 * completed successfully.
	 */
	private function markEntitlementConsumed(
		SigningSettlementContextDTO $context,
		string $settlementPath,
		SettlementSource $source,
	): void {
		$signRequest = $context->signRequest;

		$metadata = $signRequest->getMetadata() ?? [];

		$metadata['entitlement_consumed'] = true;
		$metadata['productCode'] = $context->product->getCode();
		$metadata['entitlementId'] = $context->entitlement->getId();

		// Record the settlement source only within the committing transaction.
		// The first successful settlement owns the attribution; later attempts
		// must not overwrite it.
		if (!isset($metadata['settlement_source'])) {
			$metadata['settlement_source'] = $source->value;
		}

		$signRequest->setMetadata($metadata);

		$this->signRequestMapper->update($signRequest);

		// This confirms the marker was written within the transaction, not yet
		// committed. Durable settlement is logged after commit in settle().
		$this->logger->debug(
			self::LOG_PREFIX . ' Entitlement-consumed marker persisted within transaction (pending commit).',
			[
				'signRequestId' => $signRequest->getId(),
				'entitlementId' => $context->entitlement->getId(),
				'settlementPath' => $settlementPath,
				'outcome' => 'marker_written_pending_commit',
			],
		);
	}
}
