/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import SignatureProfileSettings from '../../../../views/Settings/SignatureProfile/SignatureProfileSettings.vue'

let stateOverrides: Record<string, unknown> = {}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((_app: string, key: string, defaultValue?: unknown) => {
		const defaults: Record<string, unknown> = {
			appearance_profiles: {},
			appearance_profiles_removed: [],
			appearance_profiles_enabled: true,
		}
		return key in stateOverrides ? stateOverrides[key] : (defaults[key] ?? defaultValue)
	}),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, string: string) => string),
	n: vi.fn((_app: string, singular: string, plural: string, count: number) => count === 1 ? singular : plural),
}))

vi.mock('@nextcloud/vue/components/NcSettingsSection', () => ({
	default: {
		name: 'NcSettingsSection',
		template: '<section class="nc-settings-section-stub"><slot /></section>',
	},
}))

vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: {
		name: 'NcNoteCard',
		template: '<div class="nc-note-card-stub"><slot /></div>',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureProfileModal.vue', () => ({
	default: {
		name: 'SignatureProfileModal',
		props: ['open', 'profiles'],
		template: '<div class="signature-profile-modal-stub" />',
	},
}))

describe('SignatureProfileSettings.vue', () => {
	const createWrapper = () => mount(SignatureProfileSettings)

	beforeEach(() => {
		stateOverrides = {}
	})

	it('renders the launch button and opens the modal when clicked', async () => {
		stateOverrides = { appearance_profiles_enabled: true }
		const wrapper = createWrapper()

		expect(wrapper.find('.gp-appearance-launch').exists()).toBe(true)

		const modal = wrapper.findComponent({ name: 'SignatureProfileModal' })
		expect(modal.props('open')).toBe(false)

		await wrapper.find('.gp-appearance-launch').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'SignatureProfileModal' }).props('open')).toBe(true)
	})

	it('passes the loaded profile map to the modal', async () => {
		stateOverrides = {
			appearance_profiles: {
				admin: { footer: false, qr: true, auditInfo: true },
			},
		}
		const wrapper = createWrapper()

		expect(wrapper.findComponent({ name: 'SignatureProfileModal' }).props('profiles')).toEqual({
			admin: { footer: false, qr: true, auditInfo: true },
		})
	})
})
