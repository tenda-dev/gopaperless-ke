/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLanguage } from '@nextcloud/l10n'

const locale = () => (getLanguage() || 'en').replace('_', '-')

export function formatInt(n: number): string {
	return new Intl.NumberFormat(locale()).format(n)
}

/** `rate` is 0..1; null (no terminal attempts) renders as an em dash, never 0%. */
export function formatPercent(rate: number | null): string {
	if (rate === null) {
		return '—'
	}
	return new Intl.NumberFormat(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(rate * 100)
}

/**
 * Amounts arrive in integer minor units (cents); no FX conversion happens anywhere.
 * Shown in whole units (rounded); the API keeps the exact minor-unit total.
 */
export function formatMoney(amountMinor: number, currency: string): string {
	try {
		return new Intl.NumberFormat(locale(), { style: 'currency', currency, maximumFractionDigits: 0 }).format(amountMinor / 100)
	} catch {
		return `${formatInt(Math.round(amountMinor / 100))} ${currency}`
	}
}

const EAT = 'Africa/Nairobi'

/** YYYY-MM-DD in Africa/Nairobi, offset by whole days. */
export function eatDate(offsetDays = 0, now: Date = new Date()): string {
	const fmt = new Intl.DateTimeFormat('en-CA', { timeZone: EAT, year: 'numeric', month: '2-digit', day: '2-digit' })
	const [y, m, d] = fmt.format(now).split('-').map(Number)
	const base = new Date(Date.UTC(y, m - 1, d + offsetDays))
	return base.toISOString().slice(0, 10)
}

// The input is already a local (Nairobi) calendar day, so it is formatted as UTC
// to stop the viewer's own timezone from shifting it by a day.
export function shortDay(isoDate: string): string {
	return new Intl.DateTimeFormat(locale(), { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(isoDate + 'T00:00:00Z'))
}
