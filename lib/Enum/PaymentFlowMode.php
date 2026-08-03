<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum PaymentFlowMode: string {
	case STK_PUSH = 'stk_push';
	case INSTRUCTIONS = 'instructions';
	case BOTH = 'both';
}
