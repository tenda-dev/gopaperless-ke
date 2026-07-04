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
 * The referenced payment does not exist.
 *
 * Thrown by the fetch helpers when a providerReference / id resolves to
 * no row. Not retryable: re-issuing the same lookup provides the same miss.
 */
final class PaymentNotFoundException extends PaymentException
{
	public function __construct(
		string $message = 'Payment not found',
		PaymentErrorCode $errorCode = PaymentErrorCode::NOT_FOUND,
		?Throwable $previous = null,
	) {
		parent::__construct($errorCode, $message, false, $previous);
	}

	public function getHttpStatus(): int
	{
		return Http::STATUS_NOT_FOUND; // 404
	}
}
