<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Enum\SponsorshipType;
use Psr\Log\LoggerInterface;

/**
 * Records sponsorship relationships between
 * requesters and signers.
 *
 * Purpose:
 * Determine who is responsible for paying
 * when a signer completes a signing action.
 *
 * Sponsorship records survive for the lifetime
 * of the workflow and are resolved during signing.
 */
class SignerSponsorshipService {
	public function __construct(
		private SignerSponsorshipMapper $signerSponsorshipMapper,
		private LoggerInterface $logger,
	) {
	}

	public function create(
		int $fileId,
		int $signRequestId,
		string $sponsorUserId,
		SponsorshipType $sponsorshipType = SponsorshipType::SELF,
	): SignerSponsorship {

		$sponsorship = new SignerSponsorship();

		$sponsorship->setFileId($fileId);
		$sponsorship->setSignRequestId($signRequestId);
		$sponsorship->setSponsorUserId($sponsorUserId);
		$sponsorship->setSponsorshipTypeEnum($sponsorshipType);

		$sponsorship->validate();

		$created = $this->signerSponsorshipMapper->insert($sponsorship);

		$this->logger->info(
			'[SignerSponsorshipService] sponsorship created',
			[
				'fileId' => $fileId,
				'signRequestId' => $signRequestId,
				'sponsorUserId' => $sponsorUserId,
				'sponsorshipType' => $sponsorshipType->value,
			]
		);

		return $created;
	}

	/**
	 * @param int[] $signRequestIds
	 */
	public function createMany(
		int $fileId,
		array $signRequestIds,
		string $sponsorUserId,
		SponsorshipType $sponsorshipType = SponsorshipType::SELF,
	): void {

		foreach ($signRequestIds as $signRequestId) {
			$this->create(
				$fileId,
				(int)$signRequestId,
				$sponsorUserId,
				$sponsorshipType,
			);
		}
	}

	public function getBySignRequestId(
		int $signRequestId,
	): ?SignerSponsorship {

		return $this->signerSponsorshipMapper
			->findBySignRequestId($signRequestId);
	}

	/**
	 * @return SignerSponsorship[]
	 */
	public function getByFileId(
		int $fileId,
	): array {

		return $this->signerSponsorshipMapper
			->findByFileId($fileId);
	}

	/**
	 * @return SignerSponsorship[]
	 */
	public function getBySponsorUserId(
		string $userId,
	): array {

		return $this->signerSponsorshipMapper
			->findBySponsorUserId($userId);
	}

	/**
	 * @return SignerSponsorship[]
	 */
	public function getRequesterSponsoredByFileId(
		int $fileId,
	): array {

		return $this->signerSponsorshipMapper
			->findByFileIdAndSponsorshipType(
				$fileId,
				SponsorshipType::REQUESTER->value,
			);
	}

	public function getIndexedBySignRequestIds(
		array $signRequestIds,
	): array {
		return $this->signerSponsorshipMapper
			->findIndexedBySignRequestIds(
				$signRequestIds,
			);
	}

	public function deleteBySignRequestId(
		int $signRequestId,
	): void {

		$this->signerSponsorshipMapper
			->deleteBySignRequestId($signRequestId);

		$this->logger->info(
			'[SignerSponsorshipService] sponsorship deleted',
			[
				'signRequestId' => $signRequestId,
			]
		);
	}

	public function deleteByFileId(
		int $fileId,
	): void {

		$this->signerSponsorshipMapper
			->deleteByFileId($fileId);

		$this->logger->info(
			'[SignerSponsorshipService] sponsorship deleted',
			[
				'fileId' => $fileId,
			]
		);
	}

	public function isRequesterSponsored(
		int $signRequestId,
	): bool {

		$sponsorship = $this->getBySignRequestId(
			$signRequestId
		);

		if ($sponsorship === null) {
			return false;
		}

		return $sponsorship->getSponsorshipTypeEnum()
			=== SponsorshipType::REQUESTER;
	}

	/**
	 * Resolve who pays for this signing.
	 *
	 * Sponsored signings are resolved through persisted
	 * sponsorship records.
	 *
	 * Otherwise the signer pays.
	 */
	public function findSignRequestSponsorship(
		int $signRequestId,
	): ?SignerSponsorship {
		$sponsorship = $this->getBySignRequestId($signRequestId);

		if ($sponsorship === null) {
			return null;
		}

		if ($sponsorship->getSponsorUserId() === null) {
			return null;
		}

		if ($sponsorship->getSponsorshipTypeEnum() !== SponsorshipType::REQUESTER) {
			return null;
		}

		return $sponsorship;
	}
}
