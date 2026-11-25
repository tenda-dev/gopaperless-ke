/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Onboarding recovery admin settings: reads the server flag + onboarding URL and
 * persists changes through the dedicated admin endpoint. The toggle saves on its
 * own (URL untouched); the URL has an explicit validated save shared with the
 * public Register link.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const PROD_URL = 'https://tendaworld.com/get-started'
const state = vi.hoisted(() => ({ enabled: false, registerUrl: 'https://tendaworld.com/get-started' }))
const post = vi.hoisted(() => vi.fn(() => Promise.resolve()))
const showError = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => {
		if (key === 'onboarding_recovery_enabled') return state.enabled
		if (key === 'register_url') return state.registerUrl
		return def
	},
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof import('@nextcloud/l10n')>()),
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (p: string) => '/ocs' + p }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess: vi.fn() }))

const ENDPOINT = '/ocs/apps/libresign/api/v1/admin/onboarding-recovery-config'

const NcSettingsSectionStub = { props: ['name', 'description'], template: '<div><slot /></div>' }
const NcCheckboxRadioSwitchStub = {
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<button class="sw" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
}
const NcTextFieldStub = {
	props: ['modelValue', 'label', 'error', 'helperText', 'type'],
	emits: ['update:modelValue'],
	template: '<input class="url" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
}
const NcButtonStub = {
	props: ['disabled', 'type'],
	emits: ['click'],
	template: '<button class="save-url" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}

async function mountSection() {
	vi.resetModules()
	const { default: OnboardingRecovery } = await import('../../../views/Settings/OnboardingRecovery.vue')
	return mount(OnboardingRecovery, {
		global: {
			stubs: {
				NcSettingsSection: NcSettingsSectionStub,
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
				NcTextField: NcTextFieldStub,
				NcButton: NcButtonStub,
			},
		},
	})
}

afterEach(() => {
	post.mockClear()
	showError.mockClear()
	state.enabled = false
	state.registerUrl = PROD_URL
})

describe('OnboardingRecovery.vue', () => {
	it('renders the toggle label', async () => {
		expect((await mountSection()).text()).toContain('Enable Tendaworld onboarding for unregistered signers')
	})

	it('toggle saves only the enabled flag (URL left untouched server-side)', async () => {
		const w = await mountSection()
		await w.find('button.sw').trigger('click')
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(ENDPOINT, { enabled: true })
	})

	it('loads the configured onboarding URL into the field', async () => {
		state.registerUrl = 'https://staging.tendaworld.com/get-started'
		const w = await mountSection()
		expect((w.find('input.url').element as HTMLInputElement).value).toBe('https://staging.tendaworld.com/get-started')
	})

	it('defaults the field to the production URL when none is configured', async () => {
		state.registerUrl = PROD_URL
		const w = await mountSection()
		expect((w.find('input.url').element as HTMLInputElement).value).toBe(PROD_URL)
	})

	it('save button is disabled until the URL actually changes', async () => {
		const w = await mountSection()
		expect(w.find('button.save-url').attributes('disabled')).toBeDefined()
	})

	it('saves a changed valid URL with the current enabled flag', async () => {
		state.enabled = true
		const w = await mountSection()
		await w.find('input.url').setValue('https://staging.tendaworld.com/get-started')
		const btn = w.find('button.save-url')
		expect(btn.attributes('disabled')).toBeUndefined()
		await btn.trigger('click')
		expect(post).toHaveBeenCalledWith(ENDPOINT, { enabled: true, registerUrl: 'https://staging.tendaworld.com/get-started' })
	})

	it('keeps the save button disabled for an invalid URL (no post)', async () => {
		const w = await mountSection()
		await w.find('input.url').setValue('not-a-url')
		expect(w.find('button.save-url').attributes('disabled')).toBeDefined()
		expect(post).not.toHaveBeenCalled()
	})

	it('rejects a non-web scheme on the client guard', async () => {
		const w = await mountSection()
		await w.find('input.url').setValue('javascript:alert(1)')
		expect(w.find('button.save-url').attributes('disabled')).toBeDefined()
	})
})
