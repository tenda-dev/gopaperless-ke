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
 * A request-level validation failure: a required field is missing, a
 * phone number won't resolve, a region is unsupported, an MNO selection
 * is invalid, etc.
 *
 * RETRYABLE IS PER THROW. This is the class where it matters most:
 * - "signRequestId is required"        → NOT retryable (client bug)
 * - "unable to resolve phone number"   → retryable (user fixes input)
 * - "unsupported region"               → NOT retryable (won't change)
 *
 * The constructor therefore REQUIRES retryable — there is no safe default,
 * because guessing wrong steers the FE to the wrong CTA. Each throw site
 * states it explicitly.
 */
final class PaymentValidationException extends PaymentException
{
	public function __construct(
		PaymentErrorCode $errorCode,
		string $message,
		bool $retryable,
		?Throwable $previous = null,
	) {
		parent::__construct($errorCode, $message, $retryable, $previous);
	}

	public function getHttpStatus(): int
	{
		return Http::STATUS_UNPROCESSABLE_ENTITY; // 422
	}
}
