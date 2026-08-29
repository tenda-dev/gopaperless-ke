/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

import PublicLanding from '../../../views/Settings/PublicLanding.vue'

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: {
		modelValue: {
			type: Boolean,
			default: false,
		},
		type: String,
	},
	emits: ['update:modelValue'],
	template: '<button role="switch" :aria-checked="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
}

const OCP = {
	AppConfig: {
		setValue: vi.fn(),
	},
}

;(globalThis as typeof globalThis & { OCP: typeof OCP }).OCP = OCP

function createWrapper() {
	return mount(PublicLanding, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
			},
		},
	})
}

describe('PublicLanding.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('loads all three feature flags from initial state with default false', () => {
		const wrapper = createWrapper()

		expect(wrapper.vm.uploadLandingEnabled).toBe(false)
		expect(wrapper.vm.accountCreationEnabled).toBe(false)
		expect(wrapper.vm.acceptTermsEnabled).toBe(false)
	})

	it('loads enabled flags from initial state', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'public_upload_landing_enabled') return true
			if (key === 'public_account_creation_enabled') return true
			if (key === 'public_accept_terms_enabled') return false
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.uploadLandingEnabled).toBe(true)
		expect(wrapper.vm.accountCreationEnabled).toBe(true)
		expect(wrapper.vm.acceptTermsEnabled).toBe(false)
	})

	it('saves each flag as 1 or 0 when toggled', async () => {
		const wrapper = createWrapper()
		const switches = wrapper.findAllComponents(NcCheckboxRadioSwitchStub)

		expect(switches).toHaveLength(3)

		await switches[0].vm.$emit('update:modelValue', true)
		await switches[1].vm.$emit('update:modelValue', true)
		await switches[2].vm.$emit('update:modelValue', false)

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'public_upload_landing_enabled', '1')
		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'public_account_creation_enabled', '1')
		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'public_accept_terms_enabled', '0')
	})
})
