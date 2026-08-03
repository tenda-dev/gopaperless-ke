<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\ResolutionConfidence;

final readonly class MnoSuggestionValidationResultDTO {
	public function __construct(
		public ResolutionConfidence $confidence,
		/**
		 * Actual mno identifier provider expects.
		 */
		public ?string $resolvedMno = null,
	) {
	}
}
