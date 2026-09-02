/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

type SmsTokenVm = {
	smsOtpEnabled: boolean
	toggleSetting: (setting: string, value: boolean) => void
}

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

let SmsToken: unknown

beforeAll(async () => {
	;({ default: SmsToken } = await import('../../../views/Settings/SmsToken.vue'))
})

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

const NcTextAreaStub = {
	name: 'NcTextArea',
	props: {
		label: String,
		placeholder: String,
		modelValue: String,
		type: String,
	},
	template: '<textarea class="text-area" :placeholder="placeholder" />',
}

function createWrapper() {
	return mount(SmsToken as never, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
				NcTextArea: NcTextAreaStub,
			},
		},
	})
}

function mockLoadState(overrides: Record<string, unknown> = {}) {
	const state: Record<string, unknown> = {
		identify_methods: [{ name: 'email', enabled: true }],
		sms_otp_enabled: false,
		tiara_api_key_set: false,
		tiara_sender_id: '',
		...overrides,
	}

	loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
		return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback
	})
}

describe('SmsToken', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockLoadState()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('sends "1"/"0" strings, never a boolean', () => {
		const setValueMock = vi.fn()
		vi.stubGlobal('OCP', { AppConfig: { setValue: setValueMock } })

		const vm = createWrapper().vm as unknown as SmsTokenVm

		vm.toggleSetting('sms_otp_enabled', true)
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'sms_otp_enabled', '1')

		vm.toggleSetting('sms_otp_enabled', false)
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'sms_otp_enabled', '0')
	})

	it('persists through the switch binding when toggled', async () => {
		const setValueMock = vi.fn()
		vi.stubGlobal('OCP', { AppConfig: { setValue: setValueMock } })

		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.trigger('click')
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'sms_otp_enabled', '1')

		await switchBtn.trigger('click')
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'sms_otp_enabled', '0')
	})

	it('masks the API-key placeholder when a key is already set', () => {
		mockLoadState({ tiara_api_key_set: true })

		const wrapper = createWrapper()

		expect(wrapper.find('.text-area').attributes('placeholder')).toBe('••••••••')
	})

	it('shows the empty API-key placeholder when no key is set', () => {
		const wrapper = createWrapper()

		expect(wrapper.find('.text-area').attributes('placeholder')).toBe('Your Tiara API Key')
	})
})
