<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum PaymentFlow: string {
	case REDIRECT = 'redirect';
	case MOBILE_DIRECT = 'mobile_direct';
	case CALLBACK = 'callback';
	case UNKNOWN = 'unknown';
}
