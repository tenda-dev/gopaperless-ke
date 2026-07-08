<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

use OCA\Libresign\Enum\SponsorshipType;

final class IncomingSignerDTO
{
	public function __construct(
		private string $identifierKey,
		private string $identifierValue,
		private string $displayName,
		private SponsorshipType $requestedSponsorshipType,
		private ?int $signRequestId = null,
	) {
	}

	public function getIdentifierKey(): string
	{
		return $this->identifierKey;
	}

	public function getIdentifierValue(): string
	{
		return $this->identifierValue;
	}

	public function getDisplayName(): string
	{
		return $this->displayName;
	}

	public function getRequestedSponsorshipType(): SponsorshipType
	{
		return $this->requestedSponsorshipType;
	}

	public function getSignRequestId(): ?int
	{
		return $this->signRequestId;
	}

	public function setSignRequestId(int $signRequestId): void
	{
		$this->signRequestId = $signRequestId;
	}

	public function hasPersistedSignRequest(): bool
	{
		return $this->signRequestId !== null;
	}
}
