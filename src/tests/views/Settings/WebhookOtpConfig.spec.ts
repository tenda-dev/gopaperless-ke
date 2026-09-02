/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

type WebhookOtpConfigVm = {
	webhookOtpEnabled: boolean
	toggleSetting: (setting: string, value: boolean) => void
}

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

let WebhookOtpConfig: unknown

beforeAll(async () => {
	;({ default: WebhookOtpConfig } = await import('../../../views/Settings/WebhookOtpConfig.vue'))
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
	return mount(WebhookOtpConfig as never, {
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
		webhook_otp_enabled: false,
		webhook_otp_url: '',
		webhook_otp_shared_secret_set: false,
		...overrides,
	}

	loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
		return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback
	})
}

describe('WebhookOtpConfig', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockLoadState()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	// A raw JS boolean is sent as JSON true/false by OCP.AppConfig (axios) and the
	// OCS endpoint answers 400, so the toggle silently never persists.
	it('sends "1"/"0" strings, never a boolean', () => {
		const setValueMock = vi.fn()
		vi.stubGlobal('OCP', { AppConfig: { setValue: setValueMock } })

		const vm = createWrapper().vm as unknown as WebhookOtpConfigVm

		vm.toggleSetting('webhook_otp_enabled', true)
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'webhook_otp_enabled', '1')

		vm.toggleSetting('webhook_otp_enabled', false)
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'webhook_otp_enabled', '0')
	})

	it('persists through the switch binding when toggled', async () => {
		const setValueMock = vi.fn()
		vi.stubGlobal('OCP', { AppConfig: { setValue: setValueMock } })

		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.trigger('click')
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'webhook_otp_enabled', '1')

		await switchBtn.trigger('click')
		expect(setValueMock).toHaveBeenLastCalledWith('libresign', 'webhook_otp_enabled', '0')
	})

	it('shows the shared-secret placeholder when a secret is already set', () => {
		mockLoadState({ webhook_otp_shared_secret_set: true })

		const wrapper = createWrapper()

		expect(wrapper.find('.password-field').attributes('placeholder')).toBe('Shared secret is already set')
	})

	it('shows the empty shared-secret placeholder when no secret is set', () => {
		const wrapper = createWrapper()

		expect(wrapper.find('.password-field').attributes('placeholder')).toBe('Webhook shared secret')
	})
})
