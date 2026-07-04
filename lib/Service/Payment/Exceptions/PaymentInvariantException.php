<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\Exceptions;

use OCP\AppFramework\Http;
use Throwable;

/**
 * An internal invariant was violated: misconfigured product, missing
 * payment metadata, an unreachable match arm, a post-initiation
 * consistency check that failed.
 *
 * These are OUR faults, not the user's — so they are 500s. But they are
 * TYPED 500s: the controller can log a stable code and the frontend shows
 * a generic failure, instead of a raw stack-trace message leaking out.
 *
 * Not retryable: the same broken state produces the same failure.
 */
final class PaymentInvariantException extends PaymentException
{
	public function __construct(
		string $message,
		string $errorCode = 'PAYMENT_INTERNAL_ERROR',
		?Throwable $previous = null,
	) {
		parent::__construct($errorCode, $message, false, $previous);
	}

	public function getHttpStatus(): int
	{
		return Http::STATUS_INTERNAL_SERVER_ERROR; // 500
	}
}
