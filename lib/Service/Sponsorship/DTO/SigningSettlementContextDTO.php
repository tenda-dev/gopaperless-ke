<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\Product;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignerSponsorship;

final class SigningSettlementContextDTO
{
	public function __construct(
		public readonly string $signerUserId,
		public readonly ?SignRequest $signRequest = null,
		public readonly ?Product $product = null,
		/**
		 * Null for self-sponsored signings.
		 * Populated for requester-sponsored signings.
		 */
		public readonly ?SignerSponsorship $sponsorship = null,
		public readonly ?Entitlement $entitlement = null,
		public readonly ?EntitlementReservation $reservation = null,
	) {}

	public function withSignRequest(SignRequest $signRequest): self
	{
		return new self(
			signerUserId: $this->signerUserId,
			signRequest: $signRequest,
			product: $this->product,
			sponsorship: $this->sponsorship,
			entitlement: $this->entitlement,
			reservation: $this->reservation,
		);
	}

	public function withProduct(Product $product): self
	{
		return new self(
			signerUserId: $this->signerUserId,
			signRequest: $this->signRequest,
			product: $product,
			sponsorship: $this->sponsorship,
			entitlement: $this->entitlement,
			reservation: $this->reservation,
		);
	}

	public function withSponsorship(
		?SignerSponsorship $sponsorship,
	): self {
		return new self(
			signerUserId: $this->signerUserId,
			signRequest: $this->signRequest,
			product: $this->product,
			sponsorship: $sponsorship,
			entitlement: $this->entitlement,
			reservation: $this->reservation,
		);
	}

	public function withEntitlement(
		?Entitlement $entitlement,
	): self {
		return new self(
			signerUserId: $this->signerUserId,
			signRequest: $this->signRequest,
			product: $this->product,
			sponsorship: $this->sponsorship,
			entitlement: $entitlement,
			reservation: $this->reservation,
		);
	}

	public function withReservation(
		EntitlementReservation $reservation,
	): self {
		return new self(
			signerUserId: $this->signerUserId,
			signRequest: $this->signRequest,
			product: $this->product,
			sponsorship: $this->sponsorship,
			entitlement: $this->entitlement,
			reservation: $reservation,
		);
	}
}
