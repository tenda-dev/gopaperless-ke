<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\ProductMapper;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Entitlement\EntitlementService;
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
	public function __construct(
		private SignRequestMapper $signRequestMapper,
		private ProductMapper $productMapper,
		private EntitlementMapper $entitlementMapper,
		private EntitlementReservationMapper $reservationMapper,
		private SignerSponsorshipMapper $signerSponsorshipMapper,
		private EntitlementService $entitlementService,
		private LoggerInterface $logger,
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

		return $context
			->withReservation($reservation)
			->withEntitlement($entitlement)
			->withSponsorship($sponsorship);
	}

	/**
	 * Validates self-sponsored settlement.
	 *
	 * Resolves the signer's active entitlement for the requested product.
	 */
	public function validateSelfSponsored(
		SigningSettlementContextDTO $context,
	): SigningSettlementContextDTO {
		$entitlement = $this->entitlementService->getValid(
			userId: $context->signerUserId,
			productCode: $context->product->getCode(),
		);

		if ($entitlement === null) {
			$this->fail(
				'Signing settlement validation failed: signer entitlement not found.',
				[
					'userId' => $context->signerUserId,
					'productCode' => $context->product->getCode(),
				],
			);
		}

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
			$this->fail(
				'Signing settlement validation failed: entitlement already consumed.',
				[
					'signRequestId' => $signRequestId,
					'userId' => $context->signerUserId,
					'entitlementConsumed' => $metadata['entitlement_consumed'],
				],
			);
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
		$logPrefix = '[SigningSettlementValidationService]';
		$message = sprintf('%s %s', $logPrefix, $message);

		$this->logger->error($message, $context);

		throw new RuntimeException($message);
	}
}
