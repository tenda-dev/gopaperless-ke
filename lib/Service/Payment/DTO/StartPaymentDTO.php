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

		// Only necessary for card payments
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
	) {
	}

	/**
	 * Return a copy of this DTO with a different payment attempt id.
	 *
	 * Used by the retry flow: after the old payment session is expired,
	 * we hand its intent back to startPayment() to begin a fresh attempt.
	 * The attempt id MUST change, otherwise startPayment()'s idempotency
	 * lookup (findByAttemptId) would match the row we just failed and
	 * return that failed payment instead of starting a new one.
	 *
	 * Why clone instead of `new self(...)`:
	 * Cloning copies every field automatically, so this method keeps
	 * working correctly even if new fields are later added to the
	 * constructor. A hand-written `new self(...)` would silently drop
	 * any field it forgot to list.
	 * A shallow clone is safe here because every property is a scalar or
	 * an immutable enum.
	 *
	 * @param string|null $paymentAttemptId The new attempt id. Passing null
	 *        lets startPayment() generate a fresh one itself.
	 */
	public function withPaymentAttemptId(?string $paymentAttemptId): self
	{
		$clone = clone $this;
		$clone->paymentAttemptId = $paymentAttemptId;
		return $clone;
	}
}
