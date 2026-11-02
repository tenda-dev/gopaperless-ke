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

import OperationsPayments from '../../../views/Operations/components/OperationsPayments.vue'
import type { PaymentsResponse } from '../../../views/Operations/types.ts'

const rate = (unit: 'payment_attempt' | 'workflow', n: number, d: number) => ({
	grain: 'period' as const, unit, numerator: n, denominator: d, rate: d ? n / d : null,
})

const data: PaymentsResponse = {
	meta: { from: '', to: '', tz: '', inclusive: 'both', generatedAt: '' },
	metrics: {
		signingPaymentSuccess: rate('payment_attempt', 1, 2),
		signingWorkflowSuccess: rate('workflow', 1, 1),
		creditPurchaseSuccess: rate('payment_attempt', 0, 0),
		revenue: { grain: 'period', timeField: 'paid_at', byCurrency: [] },
	},
	outcomes: { signRequest: { paid: 1, failed: 1, pending: 2 }, creditPurchase: {} },
	providers: [{ provider: 'daraja', byStatus: { paid: 1, failed: 1 }, attempts: 2 }],
}

describe('OperationsPayments', () => {
	const mountIt = () => mount(OperationsPayments, {
		props: { data },
		global: { stubs: { OperationsProvenance: true } },
	})

	it('shows the three success figures separately with their own units', () => {
		const text = mountIt().find('.ops-payments__rates').text()
		expect(text).toContain('50.0%')
		expect(text).toContain('100.0%')
		expect(text).toContain('attempts')
		expect(text).toContain('workflows')
	})

	it('renders a missing rate as a dash rather than 0%', () => {
		const rates = mountIt().findAll('.ops-payments__rate')
		expect(rates[2].find('.ops-payments__rate-value').text()).toBe('—')
	})

	it('names providers and never exposes identifiers', () => {
		const html = mountIt().html()
		expect(html).toContain('Daraja')
		expect(html).not.toMatch(/payment_attempt_id|provider_reference/)
	})
})
