<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

// system execution
enum PaymentCapability: string {

	case MOBILE_MONEY = 'mobile_money';
	case CARD = 'card';

}
