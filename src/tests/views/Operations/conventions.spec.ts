/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

/**
 * The Operations view follows PaymentStep's convention: custom controls and
 * plain inline SVG icons, no @nextcloud/vue components. Theming still comes
 * from Nextcloud CSS variables via _tokens.scss.
 */
const sources = import.meta.glob('../../../views/Operations/**/*.{vue,ts,scss}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

describe('Operations view conventions', () => {
	it('finds the feature sources', () => {
		expect(Object.keys(sources).length).toBeGreaterThan(10)
	})

	it('imports nothing from @nextcloud/vue', () => {
		const offenders = Object.entries(sources)
			.filter(([file, code]) => !file.endsWith('OperationsIcon.vue') && /from\s+['"]@nextcloud\/vue/.test(code))
			.map(([file]) => file)
		expect(offenders).toEqual([])
	})

	it('has no <Nc…> component tags', () => {
		const offenders = Object.entries(sources)
			.filter(([file, code]) => file.endsWith('.vue') && !file.endsWith('OperationsIcon.vue') && /<Nc[A-Z]/.test(code))
			.map(([file]) => file)
		expect(offenders).toEqual([])
	})

	it('every <button> declares type="button"', () => {
		const offenders = Object.entries(sources)
			.filter(([file]) => file.endsWith('.vue'))
			.filter(([, code]) => (code.match(/<button\b[^>]*>/g) ?? []).some((tag) => !/type="button"/.test(tag)))
			.map(([file]) => file)
		expect(offenders).toEqual([])
	})
})
