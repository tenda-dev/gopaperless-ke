/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	t: (_app: string, text: string, vars?: Record<string, string>) =>
		vars ? text.replace(/\{(\w+)\}/g, (_m, k) => vars[k] ?? '') : text,
}))

import OperationsFundingMix from '../../../views/Operations/components/OperationsFundingMix.vue'
import type { FundingMetric } from '../../../views/Operations/types.ts'

const base: FundingMetric = {
	grain: 'period',
	unit: 'documents',
	dateField: 'completion',
	multiBucket: true,
	completedDocuments: 10,
	categories: { requesterSponsored: 4, selfEntitlement: 7, directPay: 2, unknown: 1 },
	byEntitlementType: { PAY_AS_YOU_GO: 6, PROMOTIONAL: 3, UNKNOWN: 1 },
}

describe('OperationsFundingMix', () => {
	const mountIt = (data: FundingMetric = base) => mount(OperationsFundingMix, {
		props: { data },
		global: { stubs: { OperationsProvenance: true, OperationsIcon: true } },
	})

	it('renders the four categories with their own counts', () => {
		const rows = mountIt().findAll('.ops-fund__row')
		expect(rows).toHaveLength(4)
		expect(rows.map(r => r.find('.ops-fund__value').text())).toEqual(['4', '7', '2', '1'])
	})

	it('says categories overlap, and shows the completed total, when they exceed it', () => {
		// 4 + 7 + 2 + 1 = 14 category memberships over 10 documents
		const note = mountIt().find('.ops-fund__note').text()
		expect(note).toContain('more than the 10 completed documents')
	})

	it('shows undetermined funding as its own muted category, not as "unfunded"', () => {
		const html = mountIt().html()
		expect(html).toContain('Funding undetermined')
		expect(html).not.toMatch(/unfunded/i)
		expect(mountIt().findAll('.ops-fund__fill')[3].classes()).toContain('ops-fund__fill--muted')
	})

	it('caps a bar at 100% and never divides by zero', () => {
		const widths = mountIt({ ...base, completedDocuments: 3 }).findAll('.ops-fund__fill').map(f => f.attributes('style'))
		expect(widths[1]).toContain('100%')
	})

	it('labels credit types with product wording and keeps unknown pools visible', () => {
		const pools = mountIt().find('.ops-fund__pools').text()
		expect(pools).toContain('Purchased')
		expect(pools).toContain('Promotional')
		expect(pools).toContain('Unattributed')
	})

	it('renders an empty state instead of zero-width bars when nothing completed', () => {
		const wrapper = mountIt({ ...base, completedDocuments: 0, categories: { requesterSponsored: 0, selfEntitlement: 0, directPay: 0, unknown: 0 }, byEntitlementType: {} })
		expect(wrapper.find('.ops-fund__none').exists()).toBe(true)
		expect(wrapper.find('.ops-fund__rows').exists()).toBe(false)
	})

	it('never exposes identifiers', () => {
		expect(mountIt().html()).not.toMatch(/user_id|payment_attempt_id|provider_reference|entitlementId/)
	})
})
