<?php

declare(strict_types=1);

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
	private const LOG_PREFIX = '[SigningSettlementService]';

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
	): Entitlement {
		$this->db->beginTransaction();
		$entitlement = null;

		try {
			$this->logger->info(
				self::LOG_PREFIX . ' Starting signing settlement.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $signerUserId,
					'productCode' => $productCode,
				],
			);

			$context = $this->validationService->validateCommon(
				signRequestId: $signRequestId,
				signerUserId: $signerUserId,
				productCode: $productCode,
			);

			if ($context->sponsorship !== null) {
				$context = $this->validationService
					->validateRequesterSponsored($context);

				$entitlement = $this->settleRequesterSponsored($context);
			} else {
				$context = $this->validationService
					->validateSelfSponsored($context);

				$entitlement = $this->settleSelfSponsored($context);
			}

			$this->db->commit();

			$this->logger->info(
				self::LOG_PREFIX . ' Signing settlement completed successfully.',
				[
					'signRequestId' => $signRequestId,
					'entitlementId' => $entitlement->getId(),
					'remainingUses' => $entitlement->getRemainingUses(),
				],
			);

			return $entitlement;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			$this->logger->error(
				self::LOG_PREFIX . ' Signing settlement failed.',
				[
					'signRequestId' => $signRequestId,
					'userId' => $signerUserId,
					'exception' => $e,
				],
			);

			throw $e;
		}
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
	): Entitlement {
		$reservation = $context->reservation;
		$entitlement = $context->entitlement;

		$this->reservationService->applyReservationRelease(
			$reservation,
			$entitlement,
		);

		$entitlement->consume(
			$reservation->getQuantity(),
		);

		$this->entitlementMapper->update($entitlement);
		$this->reservationMapper->update($reservation);

		$this->markEntitlementConsumed($context);

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
	): Entitlement {
		$entitlement = $context->entitlement;

		$entitlement->consume();

		$this->entitlementMapper->update($entitlement);

		$this->markEntitlementConsumed($context);

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
	): void {
		$signRequest = $context->signRequest;

		$metadata = $signRequest->getMetadata() ?? [];

		$metadata['entitlement_consumed'] = true;
		$metadata['productCode'] = $context->product->getCode();
		$metadata['entitlementId'] = $context->entitlement->getId();

		$signRequest->setMetadata($metadata);

		$this->signRequestMapper->update($signRequest);
	}
}
