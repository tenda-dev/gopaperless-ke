/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	t: (_app: string, text: string, vars?: Record<string, string>) =>
		vars ? text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '') : text,
}))

import { poolLabel, poolRows } from '../../../views/Operations/utils/funding.ts'

describe('funding labels', () => {
	it('uses product wording for known pools and passes unknown keys through', () => {
		expect(poolLabel('PAY_AS_YOU_GO')).toBe('Purchased')
		expect(poolLabel('UNKNOWN')).toBe('Unattributed')
		expect(poolLabel('SOMETHING_NEW')).toBe('SOMETHING_NEW')
	})

	it('sorts by count desc then key, so ties are stable', () => {
		expect(poolRows({ B: 1, A: 1, C: 5 }).map(r => r.key)).toEqual(['C', 'A', 'B'])
	})
})
