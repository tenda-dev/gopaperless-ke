<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum PaymentPurpose: string
{
	case SIGN_REQUEST = 'sign_request';
	case CREDIT_PURCHASE = 'credit_purchase';
}
