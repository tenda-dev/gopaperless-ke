<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\DTO;

final class AutoChargeDTO {
	public function __construct(
		public readonly bool $enabled,
		public readonly ?string $provider = null,
		public readonly ?string $providerMnoKey = null,
		public readonly ?string $mno = null,
		public readonly ?string $country = null,
	) {
	}

	public function toArray(): array {
		return [
			'enabled' => $this->enabled,
			'provider' => $this->provider,
			'providerMnoKey' => $this->providerMnoKey,
			'mno' => $this->mno,
			'country' => $this->country,
		];
	}

	public static function fromArray(array $data): self {
		return new self(
			enabled: is_bool($data['enabled'] ?? null)
				? $data['enabled']
				: false,
			provider: is_string($data['provider'] ?? null)
				? $data['provider']
				: null,
			providerMnoKey: is_string($data['providerMnoKey'] ?? null)
				? $data['providerMnoKey']
				: null,
			mno: is_string($data['mno'] ?? null)
				? $data['mno']
				: null,
			country: is_string($data['country'] ?? null)
				? $data['country']
				: null,
		);
	}
}
