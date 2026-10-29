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

import OperationsEntitlements from '../../../views/Operations/components/OperationsEntitlements.vue'
import type { EntitlementsResponse } from '../../../views/Operations/types.ts'

const make = (over: Partial<EntitlementsResponse['metrics']> = {}): EntitlementsResponse => ({
	meta: { from: '', to: '', tz: '', inclusive: 'both', generatedAt: '' },
	metrics: {
		availableCredits: { grain: 'point_in_time', remaining: 10, reserved: 2, available: 8, unlimitedEntitlements: 0, byProduct: [] },
		creditGrants: { grain: 'period', byType: { ADMIN_GRANTED: 3 } },
		sponsorshipMix: { grain: 'period', byType: { requester: 5, self: 2 } },
		creditConsumption: { available: false, reason: 'deferred_v1_scope' },
		...over,
	},
})

describe('OperationsEntitlements: credits consumed + requester activity', () => {
	const mountIt = (data: EntitlementsResponse) => mount(OperationsEntitlements, {
		props: { data },
		global: { stubs: { OperationsProvenance: true, OperationsIcon: true } },
	})

	it('keeps the existing "unavailable" row when the server says so', () => {
		const w = mountIt(make())
		expect(w.find('.ops-ent__row--na').exists()).toBe(true)
		expect(w.find('.ops-ent__pill').text()).toBe('unavailable')
	})

	it('shows the consumed total instead once the metric is populated', () => {
		const w = mountIt(make({ creditConsumption: { grain: 'period', unit: 'credits', timeField: 'signed', basis: 'one_credit_per_settled_signing', total: 12, byType: {} } }))
		expect(w.find('.ops-ent__row--na').exists()).toBe(false)
		expect(w.text()).toContain('Credits consumed')
		expect(w.text()).toContain('12')
	})

	it('adds the requester sponsorship footnote without touching the existing list', () => {
		const w = mountIt(make({ sponsorshipMix: { grain: 'period', byType: { requester: 5, self: 2 }, requesterActivity: { label: 'sponsorships_created', records: 5, documents: 3 } } }))
		expect(w.find('.ops-ent__foot').text()).toBe('5 requester sponsorship records across 3 documents. Reserved credits are committed to signings in progress and are only consumed when signing succeeds.')
		expect(w.findAll('.ops-ent__group')[1].findAll('li')).toHaveLength(2)
	})

	it('omits the footnote on older servers and when nothing was created', () => {
		expect(mountIt(make()).find('.ops-ent__foot').exists()).toBe(false)
		const none = make({ sponsorshipMix: { grain: 'period', byType: {}, requesterActivity: { label: 'sponsorships_created', records: 0, documents: 0 } } })
		expect(mountIt(none).find('.ops-ent__foot').exists()).toBe(false)
	})
})
