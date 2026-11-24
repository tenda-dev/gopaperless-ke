/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Onboarding recovery admin toggle: reads the server flag and persists changes
 * through the dedicated admin endpoint (preserving the boolean config type).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const state = vi.hoisted(() => ({ enabled: false }))
const post = vi.hoisted(() => vi.fn(() => Promise.resolve()))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => (key === 'onboarding_recovery_enabled' ? state.enabled : def),
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof import('@nextcloud/l10n')>()),
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (p: string) => '/ocs' + p }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const NcSettingsSectionStub = { props: ['name', 'description'], template: '<div><slot /></div>' }
const NcCheckboxRadioSwitchStub = {
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<button class="sw" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
}

async function mountSection() {
	vi.resetModules()
	const { default: OnboardingRecovery } = await import('../../../views/Settings/OnboardingRecovery.vue')
	return mount(OnboardingRecovery, {
		global: { stubs: { NcSettingsSection: NcSettingsSectionStub, NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub } },
	})
}

afterEach(() => {
	post.mockClear()
	state.enabled = false
})

describe('OnboardingRecovery.vue', () => {
	it('renders the toggle label', async () => {
		expect((await mountSection()).text()).toContain('Enable Tendaworld onboarding for unregistered signers')
	})

	it('posts the new value to the dedicated admin endpoint on toggle', async () => {
		const w = await mountSection()
		await w.find('button.sw').trigger('click')
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('/ocs/apps/libresign/api/v1/admin/onboarding-recovery-config', { enabled: true })
	})
})
