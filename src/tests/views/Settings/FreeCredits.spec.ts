/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

import FreeCredits from '../../../views/Settings/FreeCredits.vue'

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
	return mount(FreeCredits, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
				NcTextField: true,
			},
		},
	})
}

describe('FreeCredits.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('loads enabled and uses from initial state', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'free_credits_enabled') return false
			if (key === 'free_credits_uses') return 5
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.enabled).toBe(false)
		expect(wrapper.vm.uses).toBe(5)
	})

	it('saves enabled state when the switch is toggled', async () => {
		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.vm.$emit('update:modelValue', false)
		await wrapper.vm.saveEnabled()

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'free_credits_enabled', '0')
	})

	it('saves parsed uses value when the input changes', () => {
		const wrapper = createWrapper()

		;(wrapper.vm.uses as unknown as string) = '-3.7'
		wrapper.vm.saveUses()

		expect(wrapper.vm.uses).toBe(0)
		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'free_credits_uses', '0')
	})

	it('saves positive uses value when the input changes', () => {
		const wrapper = createWrapper()

		;(wrapper.vm.uses as unknown as string) = '7'
		wrapper.vm.saveUses()

		expect(wrapper.vm.uses).toBe(7)
		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'free_credits_uses', '7')
	})
})
