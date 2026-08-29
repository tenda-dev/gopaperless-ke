<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

/**
 * Result of resolving signing coverage.
 *
 * This DTO represents the outcome of the signing paywall decision.
 *
 * Scenarios:
 *
 * 1. Requester sponsored
 *    allowed      = true
 *    sponsored    = true
 *    sponsorUserId  = requester
 *
 * 2. Self sponsored
 *    allowed      = true
 *    sponsored    = false
 *    sponsorUserId  = signer
 *
 * 3. No coverage
 *    allowed      = false
 *    sponsored    = false
 *    sponsorUserId  = null
 */
final readonly class SigningCoverageResolutionDTO {
	public function __construct(
		public bool $allowed,
		public bool $sponsored,
		public ?string $sponsorUserId,
	) {
	}

	public function requiresPayment(): bool {
		return !$this->allowed;
	}

	public function isRequesterSponsored(): bool {
		return $this->allowed && $this->sponsored;
	}

	public function isSelfSponsored(): bool {
		return $this->allowed && !$this->sponsored;
	}

	public function toArray(): array {
		return [
			'allowed' => $this->allowed,
			'sponsored' => $this->sponsored,
			'sponsorUserId' => $this->sponsorUserId,
		];
	}
}
