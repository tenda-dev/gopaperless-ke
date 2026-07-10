<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Enum\SponsorshipType;

final class SponsorshipDTO
{
	public function __construct(
		private SponsorshipType $type,
		private bool $sponsored,
		private ?string $sponsorUserId,
	) {}

	public static function selfSponsored(): self
	{
		return new self(
			type: SponsorshipType::SELF,
			sponsored: false,
			sponsorUserId: null,
		);
	}

	public static function requesterSponsored(
		string $sponsorUserId,
	): self {
		return new self(
			type: SponsorshipType::REQUESTER,
			sponsored: true,
			sponsorUserId: $sponsorUserId,
		);
	}

	public static function fromEntity(
		?SignerSponsorship $entity,
	): self {
		if ($entity === null) {
			return self::selfSponsored();
		}

		return new self(
			type: $entity->getSponsorshipTypeEnum(),
			sponsored: $entity->getSponsorshipTypeEnum() !== SponsorshipType::SELF,
			sponsorUserId: $entity->getSponsorUserId(),
		);
	}

	public function getType(): SponsorshipType
	{
		return $this->type;
	}

	public function isSponsored(): bool
	{
		return $this->sponsored;
	}

	public function isSelfSponsored(): bool
	{
		return $this->type === SponsorshipType::SELF;
	}

	public function getSponsorUserId(): ?string
	{
		return $this->sponsorUserId;
	}

	public function withType(
		SponsorshipType $type,
	): self {
		$clone = clone $this;
		$clone->type = $type;

		return $clone;
	}

	public function withSponsored(
		bool $sponsored,
	): self {
		$clone = clone $this;
		$clone->sponsored = $sponsored;

		return $clone;
	}

	public function withSponsorUserId(
		?string $sponsorUserId,
	): self {
		$clone = clone $this;
		$clone->sponsorUserId = $sponsorUserId;

		return $clone;
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type->value,
			'sponsored' => $this->sponsored,
			'sponsorUserId' => $this->sponsorUserId,
		];
	}
}
