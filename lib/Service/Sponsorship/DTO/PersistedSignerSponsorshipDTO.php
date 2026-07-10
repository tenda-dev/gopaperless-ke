<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Db\SignRequest;

final class PersistedSignerSponsorshipDTO
{
	public function __construct(
		private SignRequest $signRequest,
		private SponsorshipDTO $sponsorship,
	) {}

	public function getSignRequest(): SignRequest
	{
		return $this->signRequest;
	}

	public function getSponsorship(): SponsorshipDTO
	{
		return $this->sponsorship;
	}

	public function getSignRequestId(): int
	{
		return $this->signRequest->getId();
	}

	public function withSignRequest(
		SignRequest $signRequest,
	): self {
		$clone = clone $this;
		$clone->signRequest = $signRequest;

		return $clone;
	}

	public function withSponsorship(
		SponsorshipDTO $sponsorship,
	): self {
		$clone = clone $this;
		$clone->sponsorship = $sponsorship;

		return $clone;
	}

	public function toArray(): array
	{
		return [
			'signRequest' => $this->signRequest,
			'sponsorship' => $this->sponsorship->toArray(),
		];
	}
}
