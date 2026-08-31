<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

/**
 * Where a resolved MNO identity came from.
 *
 * Diagnostic only — it never influences the payment rail
 * (that stays the concern of MnoRoutingRegistry).
 */
enum PhoneMnoResolutionSource: string {
	case OVERRIDE = 'override';
	case CACHE = 'cache';
	case DETECTION = 'detection';
	case NONE = 'none';
}
