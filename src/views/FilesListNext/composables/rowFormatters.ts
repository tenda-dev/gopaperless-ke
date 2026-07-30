/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure presentation helpers for the Files list. No reactivity and no store
 * access, so they're safe to unit test and reuse across the row components.
 */
import { t } from '@nextcloud/l10n'

/**
 * Maps a LibreSign status code to a pill/thumbnail variant name.
 * Only the four core states are mapped; everything else is neutral.
 */
export function statusVariant(code: number): string {
	switch (code) {
		case 0: return 'draft'
		case 1: return 'ready'
		case 2: return 'partial'
		case 3: return 'signed'
		default: return 'neutral'
	}
}

/**
 * Status → colour, keyed by variant. These are the readable text colours used
 * by the status pills, reused for the file-card badge so its colour echoes the
 * document's status. Frozen (built once).
 */
const STATUS_COLORS: Readonly<Record<string, string>> = Object.freeze({
	draft: '#616161',
	ready: '#9a7500',
	partial: '#3f51b5',
	signed: '#1e7d32',
	neutral: '#616161',
})

/** Colour for a status code, used to tint the file-card badge. */
export function statusColor(code: number): string {
	return STATUS_COLORS[statusVariant(code)] || '#616161'
}

/**
 * Extension → badge colour. Kept INERT for now: GoPaperless only uploads PDFs,
 * so the badge is currently tinted by status (see statusColor). This stays ready
 * for when mixed file types land and per-extension colours become useful again.
 */
const EXT_COLORS: Readonly<Record<string, string>> = Object.freeze({
	pdf: '#d32f2f',
	doc: '#2b579a', docx: '#2b579a',
	xls: '#217346', xlsx: '#217346', csv: '#217346',
	ppt: '#c43e1c', pptx: '#c43e1c',
	png: '#7b1fa2', jpg: '#7b1fa2', jpeg: '#7b1fa2', gif: '#7b1fa2',
})

/** Colour for the file-card extension badge by file type. */
export function extColor(ext: string): string {
	return EXT_COLORS[(ext || '').toLowerCase()] || '#616161'
}

/**
 * Single switch for the file-card badge colour:
 *   false (default) → the standard file-type colour (PDF red);
 *   true            → tinted by the document's status.
 */
export const BADGE_TINT_BY_STATUS = false

/** Colour for the file-card badge — status-tinted or file-type, per the flag. */
export function badgeColor(ext: string, statusCode: number): string {
	return BADGE_TINT_BY_STATUS ? statusColor(statusCode) : extColor(ext)
}

/** True when a requester value looks like an email address. */
export function isEmail(value: string): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

/**
 * Normalises a timestamp (seconds, ms, or ISO string) to milliseconds.
 * Returns 0 when the value is missing or unparseable.
 */
export function timeValue(v: string | number | null): number {
	if (v === null || v === undefined) {
		return 0
	}
	const ms = typeof v === 'number' ? (v < 1e12 ? v * 1000 : v) : Date.parse(v)
	return Number.isNaN(ms) ? 0 : ms
}

/** Absolute, localised date string for a timestamp (empty when missing). */
export function absoluteTime(v: string | number | null): string {
	const ms = timeValue(v)
	return ms ? new Date(ms).toLocaleDateString() : ''
}

/**
 * True when a document changed after it was created (a status-change timestamp
 * distinct from created_at), so a secondary "updated …" hint is worth showing.
 */
export function hasUpdateSinceCreated(created: string | number | null, updated: string | number | null): boolean {
	const c = timeValue(created)
	const u = timeValue(updated)
	return c > 0 && u > 0 && Math.abs(u - c) > 1000
}

/** Compact relative time ("just now", "5m ago", …), falling back to a date. */
export function relativeTime(v: string | number | null): string {
	const ms = timeValue(v)
	if (!ms) {
		return ''
	}
	const sec = Math.round((Date.now() - ms) / 1000)
	const min = Math.round(sec / 60)
	const hr = Math.round(min / 60)
	const day = Math.round(hr / 24)
	if (sec < 60) {
		return t('libresign', 'just now')
	}
	if (min < 60) {
		return t('libresign', '{n}m ago', { n: min })
	}
	if (hr < 24) {
		return t('libresign', '{n}h ago', { n: hr })
	}
	if (day < 30) {
		return t('libresign', '{n}d ago', { n: day })
	}
	return absoluteTime(v)
}
