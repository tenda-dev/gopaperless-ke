<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Interfaces;

use OCA\Libresign\Service\PhoneNumber\DTO\PhoneNumberDTO;

interface ISMSProvider {
	/**
	 * Send an SMS message.
	 *
	 * Implementations are responsible for validating
	 * provider-specific capabilities and requirements.
	 *
	 * Returns true when the provider accepts the message
	 * for processing.
	 */
	public function send(
		PhoneNumberDTO $phone,
		string $message,
	): bool;
}
