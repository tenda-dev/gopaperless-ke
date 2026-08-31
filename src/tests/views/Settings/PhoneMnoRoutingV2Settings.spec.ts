/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

import PhoneMnoRoutingV2Settings from '../../../views/Settings/PhoneMnoRoutingV2Settings.vue'

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
	return mount(PhoneMnoRoutingV2Settings, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
				PhoneNumberExceptions: { template: '<div data-testid="phone-exceptions" />' },
			},
		},
	})
}

describe('PhoneMnoRoutingV2Settings.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('defaults to disabled when no state is provided', () => {
		const wrapper = createWrapper()

		expect(wrapper.vm.enabled).toBe(false)
		expect(wrapper.find('[data-testid="phone-exceptions"]').exists()).toBe(false)
	})

	it('loads enabled state from initial state', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'phone_mno_routing_v2_enabled') return true
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.enabled).toBe(true)
		expect(wrapper.find('[data-testid="phone-exceptions"]').exists()).toBe(true)
	})

	it('saves enabled state when the switch is toggled on', async () => {
		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.vm.$emit('update:modelValue', true)
		await wrapper.vm.saveEnabled()

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'phone_mno_routing_v2_enabled', '1')
	})

	it('saves disabled state when the switch is toggled off', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'phone_mno_routing_v2_enabled') return true
			return fallback
		})

		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.vm.$emit('update:modelValue', false)
		await wrapper.vm.saveEnabled()

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'phone_mno_routing_v2_enabled', '0')
	})
})
