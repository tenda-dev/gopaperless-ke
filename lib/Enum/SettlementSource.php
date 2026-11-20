<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Enum;

/**
 * Identifies the code path that committed an entitlement settlement.
 *
 * Stored in sign_request.metadata.settlement_source by
 * SigningSettlementService::markEntitlementConsumed for audit attribution.
 * The value is recorded only when settlement commits and cannot be replaced
 * by subsequent settlement attempts.
 */
enum SettlementSource: string {
	case SIGNED_EVENT_LISTENER = 'signed_event_listener';
	case HTTP_AFTER_SIGN = 'http_after_sign';
	case WHATSAPP = 'whatsapp';
	case RECONCILIATION_JOB = 'reconciliation_job';
}
