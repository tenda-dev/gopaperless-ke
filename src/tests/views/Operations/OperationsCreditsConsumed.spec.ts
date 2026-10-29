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

import OperationsCreditsConsumed from '../../../views/Operations/components/OperationsCreditsConsumed.vue'
import type { CreditConsumptionMetric } from '../../../views/Operations/types.ts'

const metric: CreditConsumptionMetric = {
	grain: 'period',
	unit: 'credits',
	timeField: 'signed',
	basis: 'one_credit_per_settled_signing',
	total: 12,
	byType: { ADMIN_GRANTED: 2, PAY_AS_YOU_GO: 9, UNKNOWN: 1 },
}

describe('OperationsCreditsConsumed', () => {
	const mountIt = (m: CreditConsumptionMetric | { available: false, reason: string } = metric) => mount(OperationsCreditsConsumed, {
		props: { metric: m },
		global: { stubs: { OperationsProvenance: true, OperationsIcon: true } },
	})

	it('shows the total and the per-type breakdown, largest first', () => {
		const w = mountIt()
		expect(w.find('.ops-cons__number').text()).toBe('12')
		expect(w.findAll('.ops-cons__list li').map(li => li.text())).toEqual(['Purchased9', 'Admin-granted2', 'Unattributed1'])
	})

	it('renders the unavailable message for an unavailable metric, never a fabricated 0', () => {
		const w = mountIt({ available: false, reason: 'deferred_v1_scope' })
		expect(w.text()).toContain('Credit consumption data is unavailable')
		expect(w.find('.ops-cons__number').exists()).toBe(false)
	})

	it('flags the date as a proxy (unconfirmed provenance)', () => {
		const prov = mountIt().findComponent({ name: 'OperationsProvenance' })
		expect(prov.exists()).toBe(true)
		expect(prov.attributes('confirmed')).toBe('false')
	})

	it('states the basis so a count is not read as a balance change', () => {
		expect(mountIt().find('.ops-cons__note').text()).toContain('Unlimited credit entitlements are counted as one credit per signing')
	})
})
