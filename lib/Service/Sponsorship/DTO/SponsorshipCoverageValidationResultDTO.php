<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

final class SponsorshipCoverageValidationResultDTO
{
	public function __construct(
		private bool $allowed,
		private int $requiredCredits,
		private int $availableCredits,
		private int $missingCredits,
		private array $blockingSignRequestIds = [],
	) {
	}

	public function isAllowed(): bool
	{
		return $this->allowed;
	}

	public function getRequiredCredits(): int
	{
		return $this->requiredCredits;
	}

	public function getAvailableCredits(): int
	{
		return $this->availableCredits;
	}

	public function getMissingCredits(): int
	{
		return $this->missingCredits;
	}

	public function getBlockingSignRequestIds(): array
	{
		return $this->blockingSignRequestIds;
	}
}
