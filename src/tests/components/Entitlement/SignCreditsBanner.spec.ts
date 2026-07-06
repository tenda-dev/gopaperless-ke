/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import SignCreditsBanner from '../../../components/Entitlement/SignCreditsBanner.vue'
import { usePaymentContextStore } from '../../../store/paymentContext'

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

vi.mock('@/payment/entitlement', () => ({
	getCurrentEntitlement: vi.fn(() => Promise.resolve({
		productCode: 'SIGN_DOCUMENT',
		remainingUses: 0,
		activeEntitlements: 0,
		canUse: false,
	})),
}))

function createWrapper() {
	return mount(SignCreditsBanner, {
		global: {
			stubs: {
				NcIconSvgWrapper: true,
			},
		},
	})
}

describe('SignCreditsBanner.vue', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('renders the one-time signing option by default', async () => {
		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.text()).toContain('Buy required credits')
		expect(wrapper.text()).toContain('Recommended')
	})

	it('hides the one-time option and forces credit-pack flow when disabled', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'config') {
				return { one_time_signing_enabled: false }
			}
			return fallback
		})

		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.text()).not.toContain('Buy required credits')
		expect(wrapper.text()).not.toContain('Recommended')
		expect(usePaymentContextStore().flowType).toBe('CREDIT_PACK')
	})
})
