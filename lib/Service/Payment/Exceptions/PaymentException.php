<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\Exceptions;

use OCA\Libresign\Enum\PaymentErrorCode;
use RuntimeException;
use Throwable;

/**
 * Base type for all payment-domain failures that carry a client-facing
 * contract: a stable machine code, an HTTP status, and whether retrying
 * the same request could plausibly succeed.
 *
 * The errorCode is a PaymentErrorCode enum case — the single source of
 * truth for the FE-facing code vocabulary. Callers cannot pass an
 * unmapped raw string; the type system guarantees every thrown code
 * exists in the enum (and therefore in the FE PAYMENT_FAILURE_MESSAGES
 * contract).
 *
 * getMessage() (inherited) is for LOGS and developers, NOT for display.
 * isRetryable() is advisory FE steering (try-again vs restart), set per
 * throw — two failures of the same class may differ.
 */
abstract class PaymentException extends RuntimeException
{
	public function __construct(
		private readonly PaymentErrorCode $errorCode,
		string $message,
		private readonly bool $retryable,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Stable machine code the frontend maps to a localised message.
	 * Serialize via ->value at the controller boundary.
	 */
	public function getErrorCode(): PaymentErrorCode
	{
		return $this->errorCode;
	}

	abstract public function getHttpStatus(): int;

	public function isRetryable(): bool
	{
		return $this->retryable;
	}
}
