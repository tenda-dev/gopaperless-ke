<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\SponsorshipType;

/**
 * Represents sponsorship state within the sponsorship workflow.
 *
 * This DTO is used while creating, editing and persisting sponsorship
 * relationships for signers. It models the sponsorship configuration of a
 * workflow rather than the state of an active signing.
 *
 * For the signing experience (badges, payer information, etc.), use
 * SigningSponsorshipDTO instead.
 */
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
		?string $sponsorUserId = null,
	): self {
		return new self(
			type: SponsorshipType::REQUESTER,
			sponsored: true,
			sponsorUserId: $sponsorUserId,
		);
	}

	public static function fromDraftSignRequest(
		SignRequestEntity $signRequest,
	): self {

		return match ($signRequest->getSponsorshipTypeEnum()) {
			SponsorshipType::SELF => self::selfSponsored(),
			SponsorshipType::REQUESTER => self::requesterSponsored(),
		};
	}

	public static function fromEntity(
		?SignerSponsorship $entity,
	): self {
		if ($entity === null) {
			return self::selfSponsored();
		}

		return match ($entity->getSponsorshipTypeEnum()) {
			SponsorshipType::SELF => self::selfSponsored(),
			SponsorshipType::REQUESTER => self::requesterSponsored(
				$entity->getSponsorUserId(),
			),
		};
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
