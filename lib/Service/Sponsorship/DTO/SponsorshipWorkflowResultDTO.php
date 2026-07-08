<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

final readonly class SponsorshipWorkflowResultDTO
{
	public function __construct(
		private SponsorshipReconciliationResultDTO $reconciliation,
		private SponsorshipCoverageValidationResultDTO $coverage,
		private string $requesterUserId,
		private string $productCode,
	) {}

	public function reconciliation(): SponsorshipReconciliationResultDTO
	{
		return $this->reconciliation;
	}

	public function coverage(): SponsorshipCoverageValidationResultDTO
	{
		return $this->coverage;
	}

	public function requesterUserId(): string
	{
		return $this->requesterUserId;
	}

	public function productCode(): string
	{
		return $this->productCode;
	}

	public function allowed(): bool
	{
		return $this->coverage->isAllowed();
	}
}
