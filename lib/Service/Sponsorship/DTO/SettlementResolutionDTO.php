<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;


final class SettlementResolutionDTO
{
	public function __construct(
		public readonly bool $sponsored,
		public readonly string $payerUserId,
	) {}

	public function toArray(): array
	{
		return [
			'sponsored' => $this->sponsored,
			'payerUserId' => $this->payerUserId,
		];
	}
}
