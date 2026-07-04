<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base type for all payment-domain failures that carry a client-facing
 * contract: a stable machine code, an HTTP status, and whether retrying
 * the same request can actually succeed.
 *
 * WHY THIS EXISTS:
 * The payment flow historically threw raw RuntimeExceptions whose string
 * messages leaked to the client as 500s. The frontend could not tell a
 * user-fixable problem ("that phone number didn't resolve") from a client
 * bug ("signRequestId missing") from our own broken state ("missing
 * metadata"). This base lets one controller catch translate any payment
 * failure into { code, message, retryable } at the right HTTP status,
 * while genuinely unexpected errors still fall through to a bare 500.
 *
 * CONTRACT NOTES:
 * - getErrorCode() is a STABLE identifier the frontend keys its localised
 *   messages off. Treat these strings like an API surface: renaming one is
 *   a breaking change for the FE PAYMENT_FAILURE_MESSAGES map.
 * - getMessage() (inherited) is for LOGS and developers, NOT for display.
 *   The frontend shows its own copy keyed by getErrorCode(). Raw messages
 *   may contain provider text or internals and must not reach the user.
 * - isRetryable() is advisory steering for the FE (try-again vs restart),
 *   NOT a guarantee. It is set PER THROW, not per class: two failures of
 *   the same class can differ (a missing required field is not retryable;
 *   an unresolvable phone number is, once the user corrects it).
 *
 * Subclasses fix httpStatus and a sensible default for retryable; any
 * throw site may override retryable to reflect the specific cause.
 */
abstract class PaymentException extends RuntimeException
{
	/**
	 * @param string         $errorCode Stable, FE-facing machine code
	 *                                  (SCREAMING_SNAKE_CASE, no provider
	 *                                  names or internals).
	 * @param string         $message   Developer/log-facing detail. Never
	 *                                  shown to the user directly.
	 * @param bool           $retryable Whether re-issuing the same request
	 *                                  could plausibly succeed. Set per throw.
	 * @param Throwable|null $previous  Underlying cause, if wrapping one
	 *                                  (e.g. a provider or libphonenumber
	 *                                  exception).
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly bool $retryable,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Stable machine code the frontend maps to a localized message.
	 * Part of the FE contract — do not rename casually.
	 */
	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * HTTP status the controller should respond with.
	 * Fixed per subclass (404/403/422/500).
	 */
	abstract public function getHttpStatus(): int;

	/**
	 * Whether retrying the same request could plausibly succeed.
	 * Advisory FE steering only; set per throw.
	 */
	public function isRetryable(): bool
	{
		return $this->retryable;
	}
}
