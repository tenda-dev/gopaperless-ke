<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\ProductMapper;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Exception\EntitlementAlreadyConsumedException;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Exception\StaleEntitlementException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSettlementContextDTO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Validates settlement preconditions and builds the immutable settlement
 * context used throughout the settlement pipeline.
 *
 * Every validation step enriches the context with the entities required by
 * the selected settlement path. No state is modified by this service.
 */
class SigningSettlementValidationService {
	private const LOG_PREFIX = '[EntitlementSettlement][SigningSettlementValidationService]';

	public function __construct(
		private SignRequestMapper $signRequestMapper,
		private ProductMapper $productMapper,
		private EntitlementMapper $entitlementMapper,
		private EntitlementReservationMapper $reservationMapper,
		private SignerSponsorshipMapper $signerSponsorshipMapper,
		private LoggerInterface $logger,
		private ValidateHelper $validateHelper,
	) {
	}

	/**
	 * Performs validation shared by every settlement flow.
	 *
	 * This establishes the initial settlement context by validating the input,
	 * locking the sign request for update, resolving the settlement product and
	 * loading any associated sponsorship.
	 */
	public function validateCommon(
		int $signRequestId,
		string $signerUserId,
		string $productCode,
	): SigningSettlementContextDTO {
		$context = $this->validateInitContext(
			$signRequestId,
			$signerUserId,
		);

		$context = $this->validateSignRequest(
			$signRequestId,
			$context,
		);

		$this->validateHelper->validateSignRequestBelongsToUser(
			$signRequestId,
			$signerUserId,
		);

		$sponsorship = $this->signerSponsorshipMapper
			->findBySignRequestId($signRequestId);

		$context = $this->validateProduct(
			$productCode,
			$context,
		);

		return $context->withSponsorship($sponsorship);
	}

	/**
	 * Validates requester-sponsored settlement.
	 *
	 * Ensures the sponsorship is internally consistent and resolves the
	 * reservation and entitlement backing the sponsored signing.
	 */
	public function validateRequesterSponsored(
		SigningSettlementContextDTO $context,
	): SigningSettlementContextDTO {
		$signRequest = $context->signRequest;
		$sponsorship = $context->sponsorship;
		$signRequestId = $signRequest->getId();

		$reservation = $this->reservationMapper
			->findActiveBySignRequestIdForUpdate($signRequestId);

		if ($reservation === null) {
			$this->fail(
				'Signing settlement validation failed: active reservation not found.',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		// Reservation is locked FOR UPDATE via findActiveBySignRequestIdForUpdate,
		// then the entitlement. Same reservation-then-entitlement order as
		// releaseForSignRequest, so settle and release cannot deadlock and a
		// concurrently-released reservation surfaces as null above. The
		// releasedAt check below is defensive; under lock it should not fire.
		if ($reservation->getReleasedAt() !== null) {
			$this->fail(
				'Signing settlement validation failed: reservation already released.',
				[
					'signRequestId' => $signRequestId,
					'reservationId' => $reservation->getId(),
				],
			);
		}

		$entitlement = $this->entitlementMapper
			->findByIdForUpdate($reservation->getEntitlementId());

		if ($entitlement === null) {
			$this->fail(
				'Signing settlement validation failed: entitlement not found.',
				[
					'entitlementId' => $reservation->getEntitlementId(),
					'signRequestId' => $signRequestId,
				],
			);
		}

		if ($sponsorship === null) {
			$this->fail(
				'Signing settlement validation failed: sponsorship not found.',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		if ($sponsorship->getSponsorUserId() === null) {
			$this->fail(
				'Signing settlement validation failed: sponsorship user id is invalid.',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		if ($sponsorship->getSponsorshipTypeEnum() !== SponsorshipType::REQUESTER) {
			$this->fail(
				'Signing settlement validation failed: sponsorship type is invalid',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		$this->logger->debug(
			self::LOG_PREFIX . ' Requester-sponsored reservation validated; entitlement selected for consumption.',
			[
				'signRequestId' => $signRequestId,
				'reservationId' => $reservation->getId(),
				'entitlementId' => $entitlement->getId(),
				'outcome' => 'reservation_validated',
			],
		);

		return $context
			->withReservation($reservation)
			->withEntitlement($entitlement)
			->withSponsorship($sponsorship);
	}

	/**
	 * Validates self-sponsored settlement by selecting and revalidating an
	 * entitlement under lock.
	 *
	 * Candidates are selected in priority/FIFO order without locking. Only the
	 * selected candidate is locked and revalidated, avoiding multiple
	 * entitlement locks within the same transaction.
	 *
	 * A stale candidate triggers a transaction rollback and retry with that
	 * candidate excluded, releasing the sign request and entitlement locks
	 * before another candidate is attempted.
	 *
	 * @param list<int> $excludedEntitlementIds Candidates found stale during
	 *                                          earlier attempts in the current settlement.
	 *
	 * @throws StaleEntitlementException The selected candidate is no longer usable.
	 * @throws InsufficientEntitlementException No untried candidate remains.
	 */
	public function validateSelfSponsored(
		SigningSettlementContextDTO $context,
		array $excludedEntitlementIds = [],
	): SigningSettlementContextDTO {
		$candidates = $this->entitlementMapper->findActiveByUserAndProduct(
			$context->signerUserId,
			$context->product->getCode(),
		);

		$untried = array_values(array_filter(
			$candidates,
			static fn (Entitlement $candidate): bool => !in_array(
				$candidate->getId(),
				$excludedEntitlementIds,
				true,
			),
		));

		if ($untried === []) {
			$this->logger->warning(
				self::LOG_PREFIX . ' Signing settlement validation failed: no usable self-sponsored entitlement remains.',
				[
					'signerUserId' => $context->signerUserId,
					'productCode' => $context->product->getCode(),
					'excludedEntitlementIds' => $excludedEntitlementIds,
				],
			);

			throw new InsufficientEntitlementException();
		}

		// TODO (known limitation — self-sponsored reservations):
		// Self-sponsored signing does not reserve entitlement capacity when the
		// signing request is created. Concurrent pending requests may therefore
		// pass the coverage check against the same balance and compete at settlement.
		// Settlement remains atomic and prevents double consumption, but it cannot
		// prevent this request-time over-commitment.
		$candidate = $untried[0];

		$this->logger->debug(
			self::LOG_PREFIX . ' Self-sponsored entitlement candidate selected; acquiring lock.',
			[
				'signerUserId' => $context->signerUserId,
				'productCode' => $context->product->getCode(),
				'entitlementId' => $candidate->getId(),
				'untriedCandidateCount' => count($untried),
			],
		);

		$entitlement = $this->entitlementMapper->findByIdForUpdate($candidate->getId());

		$quantity = 1;
		if ($entitlement === null
			|| !$entitlement->canUse()
			|| ($entitlement->getRemainingUses() !== null && $entitlement->getRemainingUses() < $quantity)
		) {
			$this->logger->warning(
				self::LOG_PREFIX . ' Self-sponsored entitlement candidate was stale after lock; revalidation failed.',
				[
					'signerUserId' => $context->signerUserId,
					'entitlementId' => $candidate->getId(),
					'outcome' => 'stale_candidate',
				],
			);

			throw new StaleEntitlementException($candidate->getId(), count($untried));
		}

		$this->logger->debug(
			self::LOG_PREFIX . ' Self-sponsored entitlement lock acquired and revalidated.',
			[
				'signerUserId' => $context->signerUserId,
				'entitlementId' => $entitlement->getId(),
				'outcome' => 'revalidated',
			],
		);

		return $context->withEntitlement($entitlement);
	}

	/**
	 * Locks and validates the sign request before settlement begins.
	 *
	 * The sign request is loaded using a FOR UPDATE lock to prevent concurrent
	 * settlement and to guarantee idempotent entitlement consumption.
	 */
	private function validateSignRequest(
		int $signRequestId,
		SigningSettlementContextDTO $context,
	): SigningSettlementContextDTO {
		$signRequest = $this->signRequestMapper
			->findByIdForUpdate($signRequestId);

		if ($signRequest === null) {
			$this->fail(
				'Signing settlement validation failed: sign request not found.',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		if ($signRequest->getStatusEnum() !== SignRequestStatus::SIGNED) {
			$this->fail(
				'Signing settlement validation failed: sign request is not signed.',
				[
					'signRequestId' => $signRequestId,
					'status' => $signRequest->getStatusEnum()->value,
				],
			);
		}

		$metadata = $signRequest->getMetadata() ?? [];

		if (!empty($metadata['entitlement_consumed'])) {
			// Duplicate settlement is an expected idempotent outcome, not a failure.
			// Keep it out of error-level logging to avoid misrepresenting successful
			// prior settlement as a failure.
			$this->logger->info(
				self::LOG_PREFIX . ' Settlement skipped: entitlement already consumed for this sign request.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $context->signerUserId,
					'entitlementId' => $metadata['entitlementId'] ?? null,
					'outcome' => 'skipped_already_consumed',
				],
			);

			throw new EntitlementAlreadyConsumedException(sprintf(
				'%s Signing settlement validation failed: entitlement already consumed.',
				self::LOG_PREFIX,
			));
		}

		return $context->withSignRequest($signRequest);
	}

	/**
	 * Resolves the product being settled.
	 *
	 * The resolved product becomes the source of truth for entitlement
	 * resolution and future commercial engine integration.
	 */
	private function validateProduct(
		string $productCode,
		SigningSettlementContextDTO $context,
	): SigningSettlementContextDTO {
		$product = $this->productMapper
			->findDefaultByCode($productCode);

		if ($product === null) {
			$this->fail(
				'Signing settlement validation failed: product not found.',
				[
					'productCode' => $productCode,
				],
			);
		}

		return $context->withProduct($product);
	}

	/**
	 * Validates the settlement request and creates the initial context.
	 *
	 * This is intentionally limited to validating caller-provided input before
	 * any database interaction occurs.
	 */
	private function validateInitContext(
		int $signRequestId,
		string $signerUserId,
	): SigningSettlementContextDTO {
		if ($signRequestId <= 0) {
			$this->fail(
				'Signing settlement validation failed: invalid sign request id.',
				[
					'signRequestId' => $signRequestId,
				],
			);
		}

		if ($signerUserId === '') {
			$this->fail(
				'Signing settlement validation failed: invalid signer user id.',
				[
					'signerUserId' => $signerUserId,
				],
			);
		}

		return new SigningSettlementContextDTO(
			signerUserId: $signerUserId,
		);
	}

	/**
	 * Logs the validation failure before aborting settlement.
	 */
	private function fail(
		string $message,
		array $context = [],
	): never {
		$message = sprintf('%s %s', self::LOG_PREFIX, $message);

		$this->logger->error($message, $context);

		throw new RuntimeException($message);
	}
}
