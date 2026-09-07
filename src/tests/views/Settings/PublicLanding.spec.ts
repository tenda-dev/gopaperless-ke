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

const NcSelectStub = {
	name: 'NcSelect',
	props: {
		modelValue: {
			type: Object,
			default: null,
		},
		options: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:model-value'],
	template: `<select
		:value="modelValue ? modelValue.id : ''"
		@change="$emit('update:model-value', options.find(o => String(o.id) === $event.target.value) ?? null)">
		<option v-for="option in options" :key="option.id" :value="option.id">{{ option.label }}</option>
	</select>`,
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
				NcSelect: NcSelectStub,
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

	it('defaults to the Nextcloud Login option when no provider is configured and none are discovered', () => {
		const wrapper = createWrapper()

		expect(wrapper.vm.oidcProviderOptions).toEqual([{ id: 0, label: 'Nextcloud Login' }])
		expect(wrapper.vm.selectedOidcProvider).toEqual({ id: 0, label: 'Nextcloud Login' })
	})

	it('lists discovered User OIDC providers alongside Nextcloud Login', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'user_oidc_providers') {
				return [
					{ id: 2, label: 'GoPaperless OIDC' },
					{ id: 5, label: 'Microsoft Entra ID' },
				]
			}
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.oidcProviderOptions).toEqual([
			{ id: 0, label: 'Nextcloud Login' },
			{ id: 2, label: 'GoPaperless OIDC' },
			{ id: 5, label: 'Microsoft Entra ID' },
		])
	})

	it('preselects the currently configured provider by id', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'user_oidc_providers') {
				return [
					{ id: 2, label: 'GoPaperless OIDC' },
					{ id: 5, label: 'Microsoft Entra ID' },
				]
			}
			if (key === 'public_upload_login_provider_id') return 5
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.selectedOidcProvider).toEqual({ id: 5, label: 'Microsoft Entra ID' })
	})

	it('falls back to Nextcloud Login when the configured provider id is no longer in the discovered list', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'user_oidc_providers') {
				return [{ id: 2, label: 'GoPaperless OIDC' }]
			}
			if (key === 'public_upload_login_provider_id') return 99
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.selectedOidcProvider).toEqual({ id: 0, label: 'Nextcloud Login' })
	})

	it('saves the selected provider id when a provider is chosen', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'user_oidc_providers') {
				return [{ id: 2, label: 'GoPaperless OIDC' }]
			}
			return fallback
		})

		const wrapper = createWrapper()
		const select = wrapper.findComponent(NcSelectStub)

		await select.vm.$emit('update:model-value', { id: 2, label: 'GoPaperless OIDC' })

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'public_upload_login_provider_id', '2')
		expect(wrapper.vm.selectedOidcProvider).toEqual({ id: 2, label: 'GoPaperless OIDC' })
	})

	it('saves provider id 0 when Nextcloud Login is chosen', async () => {
		const wrapper = createWrapper()
		const select = wrapper.findComponent(NcSelectStub)

		await select.vm.$emit('update:model-value', { id: 0, label: 'Nextcloud Login' })

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'public_upload_login_provider_id', '0')
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
