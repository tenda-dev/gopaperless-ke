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

	/**
	 * One-time purchase of a personal digital certificate.
	 *
	 * Unlike SIGN_REQUEST / CREDIT_PURCHASE, this does NOT grant a
	 * use-based entitlement. At payment finalise it branches to
	 * ExtendedAccountService::renewCertificate(), which sets the
	 * account's time-bound certificate access window.
	 */
	case CERTIFICATE_PURCHASE = 'certificate_purchase';
}
