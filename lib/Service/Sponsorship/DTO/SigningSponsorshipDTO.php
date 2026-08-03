<?php

declare(strict_types=1);

/**
 * TODO (Architecture):
 * Move this service to the Signing namespace once the signing
 * settlement implementation has stabilized.
 */
namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Enum\SponsorshipType;

/**
 * Represents sponsorship state during signing.
 *
 * This lightweight read model is used by the signing experience to
 * determine whether a signing is requester-sponsored and who is funding
 * the signing.
 *
 * Unlike SponsorshipDTO, this DTO is not involved in workflow creation or
 * sponsorship persistence. It exists solely to project sponsorship
 * information to the signing UI.
 */
final readonly class SigningSponsorshipDTO {
	public function __construct(
		public SponsorshipType $type,
		public ?string $sponsorUserId = null,
	) {
	}

	public function isSponsored(): bool {
		return $this->type === SponsorshipType::REQUESTER;
	}

	public function toArray(): array {
		return [
			'type' => $this->type->value,
			'sponsored' => $this->isSponsored(),
			'sponsorUserId' => $this?->sponsorUserId,
		];
	}
}
