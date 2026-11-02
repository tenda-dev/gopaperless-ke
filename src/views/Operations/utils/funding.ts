/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

const POOL_LABELS: Record<string, () => string> = {
	PAY_AS_YOU_GO: () => t('libresign', 'Purchased'),
	SUBSCRIPTION: () => t('libresign', 'Subscription'),
	PROMOTIONAL: () => t('libresign', 'Promotional'),
	ADMIN_GRANTED: () => t('libresign', 'Admin-granted'),
	// Consumption was recorded but its entitlement id is missing or no longer resolves (legacy or deleted).
	UNKNOWN: () => t('libresign', 'Unattributed'),
}

/** Label for an entitlement type key; unrecognised keys render as-is rather than being hidden. */
export function poolLabel(key: string): string {
	return POOL_LABELS[key]?.() ?? key
}

/** Rows sorted by count (desc), then key, so equal counts do not reshuffle between renders. */
export function poolRows(byType: Record<string, number>): Array<{ key: string, label: string, count: number }> {
	return Object.entries(byType)
		.map(([key, count]) => ({ key, count, label: poolLabel(key) }))
		.sort((a, b) => b.count - a.count || a.key.localeCompare(b.key))
}
