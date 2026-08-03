/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import SignatureProfileModal from '../../../../views/Settings/SignatureProfile/SignatureProfileModal.vue'

const axiosGetMock = vi.fn()
const axiosPostMock = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((...args: unknown[]) => axiosGetMock(...args)),
		post: vi.fn((...args: unknown[]) => axiosPostMock(...args)),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php/${path.replace(/^\//, '')}`),
}))

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(() => Promise.resolve()),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, string: string, _vars?: Record<string, unknown>) => string),
	n: vi.fn((_app: string, singular: string, plural: string, count: number) => count === 1 ? singular : plural),
}))

vi.mock('@nextcloud/vue/components/NcModal', () => ({
	default: {
		name: 'NcModal',
		props: ['open', 'name'],
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureProfileList.vue', () => ({
	default: {
		name: 'SignatureProfileList',
		props: ['groups', 'loadingGroups', 'selectedGroup', 'hasCustomProfile', 'memberCount', 'note'],
		template: '<div class="signature-profile-list-stub" />',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureProfilePreview.vue', () => ({
	default: {
		name: 'SignatureProfilePreview',
		props: ['footer', 'qr', 'stamp', 'auditInfo', 'renderMode'],
		template: '<div class="signature-profile-preview-stub" />',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureStampPreview.vue', () => ({
	default: {
		name: 'SignatureStampPreview',
		props: ['enabled', 'renderMode', 'width', 'height', 'signatureFontSize', 'templateFontSize', 'templateText', 'isOverride'],
		template: '<div class="signature-stamp-preview-stub" />',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureStampConfig.vue', () => ({
	default: {
		name: 'SignatureStampConfig',
		props: ['modelValue', 'globals'],
		template: '<div class="signature-stamp-config-stub" />',
	},
}))

vi.mock('../../../../views/Settings/SignatureProfile/SignatureProfileFooter.vue', () => ({
	default: {
		name: 'SignatureProfileFooter',
		props: ['dirty', 'canSave', 'changes', 'saving'],
		template: '<div class="signature-profile-footer-stub" />',
	},
}))

describe('SignatureProfileModal.vue', () => {
	const group = { id: 'group1', displayname: 'Group 1', usercount: 5 }

	const createWrapper = (props: Record<string, unknown> = {}) => mount(SignatureProfileModal, {
		props: {
			open: true,
			profiles: {},
			...props,
		},
	})

	const selectGroup = async (wrapper: ReturnType<typeof mount>) => {
		await wrapper.findComponent({ name: 'SignatureProfileList' }).vm.$emit('update:selectedGroup', group)
		await flushPromises()
	}

	beforeEach(() => {
		axiosGetMock.mockReset()
		axiosPostMock.mockReset()

		axiosGetMock.mockResolvedValue({
			data: {
				ocs: {
					data: {
						groups: [group],
					},
				},
			},
		})

		axiosPostMock.mockResolvedValue({
			data: {
				ocs: {
					data: {},
				},
			},
		})
	})

	it('adds a default profile for a newly selected group and emits the updated map on save', async () => {
		const wrapper = createWrapper()
		await flushPromises()

		await selectGroup(wrapper)

		wrapper.findComponent({ name: 'SignatureProfileFooter' }).vm.$emit('save')
		await flushPromises()

		const defaultProfile = {
			footer: true,
			qr: true,
			auditInfo: true,
			stamp: {
				enabled: true,
				renderMode: '',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		}

		expect(axiosPostMock).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/libresign/api/v1/admin/appearance-profiles/config',
			{
				profiles: {
					group1: defaultProfile,
				},
			},
		)

		expect(wrapper.emitted('update:profiles')).toHaveLength(1)
		expect(wrapper.emitted('update:profiles')![0]).toEqual([
			{
				group1: defaultProfile,
			},
		])
	})

	it('resets an existing profile to defaults and emits the updated map on save', async () => {
		const existingProfile = {
			footer: false,
			qr: true,
			auditInfo: true,
			stamp: {
				enabled: true,
				renderMode: '',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		}

		const wrapper = createWrapper({
			profiles: {
				group1: existingProfile,
			},
		})
		await flushPromises()

		await selectGroup(wrapper)

		const resetButton = wrapper.find('.gp-appearance__reset')
		expect(resetButton.exists()).toBe(true)
		await resetButton.trigger('click')

		wrapper.findComponent({ name: 'SignatureProfileFooter' }).vm.$emit('save')
		await flushPromises()

		const defaultProfile = {
			footer: true,
			qr: true,
			auditInfo: true,
			stamp: {
				enabled: true,
				renderMode: '',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		}

		expect(axiosPostMock).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/libresign/api/v1/admin/appearance-profiles/config',
			{
				profiles: {
					group1: defaultProfile,
				},
			},
		)

		expect(wrapper.emitted('update:profiles')).toHaveLength(1)
		expect(wrapper.emitted('update:profiles')![0]).toEqual([
			{
				group1: defaultProfile,
			},
		])
	})
})
