<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Enum;

/**
 * Defines the canonical server-side product codes.
 *
 * These values mirror the frontend union in src/constants/product.ts and
 * must remain synchronized because entitlement lookups depend on them.
 */
enum ProductCode: string {
	case SIGN_DOCUMENT = 'SIGN_DOCUMENT';
	case CERTIFICATE_ACCESS = 'CERTIFICATE_ACCESS';
}
