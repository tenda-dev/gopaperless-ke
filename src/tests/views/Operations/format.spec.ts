/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en' }))

import { eatDate, formatMoney, formatPercent } from '../../../views/Operations/utils/format.ts'

describe('operations format helpers', () => {
	it('eatDate rolls to the next day after 21:00 UTC (EAT is UTC+3)', () => {
		expect(eatDate(0, new Date('2026-10-28T20:59:00Z'))).toBe('2026-10-28')
		expect(eatDate(0, new Date('2026-10-28T23:30:00Z'))).toBe('2026-10-29')
	})

	it('eatDate applies whole-day offsets', () => {
		expect(eatDate(-29, new Date('2026-10-28T10:00:00Z'))).toBe('2026-09-29')
	})

	it('a null rate renders as a dash, never 0%', () => {
		expect(formatPercent(null)).toBe('—')
		expect(formatPercent(0)).toBe('0.0')
		expect(formatPercent(0.8444)).toBe('84.4')
	})

	it('formats minor units without FX conversion', () => {
		expect(formatMoney(17334200, 'KES')).toContain('173,342')
		expect(formatMoney(17334200, 'KES')).toMatch(/KES|Ksh/)
	})
})
