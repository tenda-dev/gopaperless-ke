<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Tiara\DTO;

final readonly class TiaraConfigDTO {
	public function __construct(
		/**
		 * Tiara API bearer token.
		 */
		public string $apiKey,
		/**
		 * Approved sender ID registered with Tiara.
		 */
		public string $senderId,
		/**
		 * Tiara API endpoint URL.
		 */
		public string $apiUrl,
	) {
	}
}
