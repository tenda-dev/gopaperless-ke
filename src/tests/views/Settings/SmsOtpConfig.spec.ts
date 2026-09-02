/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

type SmsOtpConfigVm = {
	smsOtpEnabled: boolean
	toggleSetting: (setting: string, value: boolean) => void
}

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

let SmsOtpConfig: unknown

beforeAll(async () => {
	;({ default: SmsOtpConfig } = await import('../../../views/Settings/SmsOtpConfig.vue'))
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

const NcPasswordFieldStub = {
	name: 'NcPasswordField',
	props: {
		label: String,
		placeholder: String,
		modelValue: String,
	},
	template: '<input class="password-field" :placeholder="placeholder" />',
}

function createWrapper() {
	return mount(SmsOtpConfig as never, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
				NcTextField: true,
				NcPasswordField: NcPasswordFieldStub,
			},
		},
	})
}

function mockLoadState(overrides: Record<string, unknown> = {}) {
	const state: Record<string, unknown> = {
		identify_methods: [{ name: 'email', enabled: true }],
		sms_otp_enabled: false,
		tiara_api_key_set: false,
		tiara_api_url: '',
		tiara_sender_id: '',
		...overrides,
	}

	loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
		return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback
	})
}

describe('SmsOtpConfig', () => {
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

		const vm = createWrapper().vm as unknown as SmsOtpConfigVm

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

	it('shows the API-key placeholder when a key is already set', () => {
		mockLoadState({ tiara_api_key_set: true })

		const wrapper = createWrapper()

		expect(wrapper.find('.password-field').attributes('placeholder')).toBe('API key is already set')
	})

	it('shows the empty API-key placeholder when no key is set', () => {
		const wrapper = createWrapper()

		expect(wrapper.find('.password-field').attributes('placeholder')).toBe('Your Tiara API Key')
	})
})
