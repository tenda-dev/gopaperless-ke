<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\Exceptions;

use OCA\Libresign\Enum\PaymentErrorCode;
use OCP\AppFramework\Http;
use Throwable;

/**
 * The authenticated user does not own the referenced payment.
 *
 * Thrown by assertPaymentOwnership. Not retryable, and deliberately
 * carries no detail about the payment's existence or state
 */
final class PaymentOwnershipException extends PaymentException
{
	public function __construct(
		string $message = 'Access denied',
		PaymentErrorCode $errorCode = PaymentErrorCode::ACCESS_DENIED,
		?Throwable $previous = null,
	) {
		parent::__construct($errorCode, $message, false, $previous);
	}

	public function getHttpStatus(): int
	{
		return Http::STATUS_FORBIDDEN; // 403
	}
}
