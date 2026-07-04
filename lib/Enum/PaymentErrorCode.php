<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Enum;

/**
 * Stable, FE-facing payment error codes.
 *
 * Each case's backing value is the exact string the frontend keys its
 * PAYMENT_FAILURE_MESSAGES map off. Treat these values as an API surface:
 * renaming a value is a breaking change for the FE. Adding a case is safe.
 *
 * This enum is the single source of truth for the code vocabulary — the
 * payment exceptions carry a case, not a raw string, so a typo can't
 * silently mint an unmapped code.
 */
enum PaymentErrorCode: string
{
	// Validation (422) — client/user-facing
	case MISSING_FIELD      = 'PAYMENT_MISSING_FIELD';
	case INVALID_PHONE      = 'PAYMENT_INVALID_PHONE';
	case UNSUPPORTED_REGION = 'PAYMENT_UNSUPPORTED_REGION';
	case INVALID_MNO        = 'PAYMENT_INVALID_MNO';
	case PHONE_MISMATCH     = 'PAYMENT_PHONE_MISMATCH';
	case ALREADY_RESOLVED   = 'PAYMENT_ALREADY_RESOLVED';

	// Ownership / lookup
	case ACCESS_DENIED = 'PAYMENT_ACCESS_DENIED';
	case NOT_FOUND     = 'PAYMENT_NOT_FOUND';

	// Invariant (500) — our fault, coded for logging
	case INTERNAL_ERROR = 'PAYMENT_INTERNAL_ERROR';
}
