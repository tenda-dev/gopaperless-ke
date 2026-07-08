<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Enum\SponsorshipType;

final class SponsorshipChangeDTO
{
	public function __construct(
		private IncomingSignerDTO $signer,
		private ?SponsorshipType $previousSponsorshipType,
		private SponsorshipType $requestedSponsorshipType,
		private bool $isNewSigner,
	) {
	}

	public function signer(): IncomingSignerDTO
	{
		return $this->signer;
	}

	public function previousSponsorshipType(): ?SponsorshipType
	{
		return $this->previousSponsorshipType;
	}

	public function requestedSponsorshipType(): SponsorshipType
	{
		return $this->requestedSponsorshipType;
	}

	public function isNewSigner(): bool
	{
		return $this->isNewSigner;
	}

	public function requiresReservation(): bool
	{
		if ($this->isNewSigner) {
			return $this->requestedSponsorshipType === SponsorshipType::REQUESTER;
		}

		return
			$this->previousSponsorshipType === SponsorshipType::SELF
			&&
			$this->requestedSponsorshipType === SponsorshipType::REQUESTER;
	}

	public function requiresRelease(): bool
	{
		if ($this->isNewSigner) {
			return false;
		}

		return
			$this->previousSponsorshipType === SponsorshipType::REQUESTER
			&&
			$this->requestedSponsorshipType === SponsorshipType::SELF;
	}
}
