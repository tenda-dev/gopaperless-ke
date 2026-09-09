<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\DTO;

use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;

class StartPaymentDTO {
	public function __construct(
		public string $userEmail,
		// Optional for non-signing payments (credits/wallet/etc)
		public ?string $signUuid,
		// Optional for non-signing payments
		public ?int $signRequestId,
		// Defines business intent of payment
		public PaymentPurpose $purpose,
		// Payment provider callback/redirect URL
		public ?string $redirectUrl,
		public string $userId,
		// provider hint
		public ?PaymentProvider $provider,
		public string $productCode,
		// 'card' | 'mobile'
		public PaymentMethod $paymentMethod,
		public int $quantity = 1,
		public ?string $callbackUrl = null,
		public ?string $paymentAttemptId = null,
		public ?string $phoneNumber = null,
		// Where the user should be returned after payment verification
		public ?string $returnUrl = null,
	) {
	}
}
