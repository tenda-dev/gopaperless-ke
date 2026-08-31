/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import { createL10nMock } from '../../testHelpers/l10n.js'
import PhoneNumberExceptions from '../../../views/Settings/PhoneNumberExceptions.vue'

const loadStateMock = vi.fn()
const getMock = vi.fn()
const postMock = vi.fn()
const patchMock = vi.fn()
const deleteMock = vi.fn()
const showSuccessMock = vi.fn()
const showErrorMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))
vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		params?.id !== undefined ? url.replace('{id}', String(params.id)) : url,
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a: unknown[]) => getMock(...a),
		post: (...a: unknown[]) => postMock(...a),
		patch: (...a: unknown[]) => patchMock(...a),
		delete: (...a: unknown[]) => deleteMock(...a),
	},
}))
vi.mock('@/services/toast', () => ({
	showSuccess: (...a: unknown[]) => showSuccessMock(...a),
	showError: (...a: unknown[]) => showErrorMock(...a),
}))

const passthroughSlot = { template: '<div><slot /></div>' }
const stubs = {
	NcSettingsSection: passthroughSlot,
	NcDialog: passthroughSlot,
	NcNoteCard: passthroughSlot,
	NcEmptyContent: passthroughSlot,
	NcIconSvgWrapper: { template: '<span />' },
	NcTextField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<button role="switch" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
	},
}

const OVERRIDE_OPTIONS = {
	mnos: {
		KE: [{ value: 'safaricom', label: 'Safaricom' }],
	},
	providers: [
		{ value: 'daraja', label: 'Daraja' },
		{ value: 'dpo', label: 'DPO' },
	],
}

const OVERRIDE = {
	id: 1,
	phone: '+254712345678',
	mno: 'safaricom',
	provider: 'daraja',
	active: true,
	note: null,
	createdBy: null,
	createdAt: null,
	updatedAt: null,
}

function createWrapper() {
	return mount(PhoneNumberExceptions, { global: { stubs } })
}

describe('PhoneNumberExceptions.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'phone_overrides') {
				return []
			}
			if (key === 'phone_override_options') {
				return OVERRIDE_OPTIONS
			}
			if (key === 'default_phone_region') {
				return 'KE'
			}
			return fallback
		})
		getMock.mockResolvedValue({ data: { ocs: { data: { overrides: [OVERRIDE] } } } })
	})

	it('renders the manage launcher', () => {
		const wrapper = createWrapper()
		expect(wrapper.text()).toContain('Manage phone exceptions')
	})

	it('opens the modal and loads the numbers', async () => {
		const wrapper = createWrapper()
		await wrapper.vm.openModal()
		expect(wrapper.vm.open).toBe(true)
		expect(getMock).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/phone-overrides')
		expect(wrapper.vm.overrides).toHaveLength(1)
	})

	it('rejects an empty phone without calling the API', async () => {
		const wrapper = createWrapper()
		wrapper.vm.phone = '   '
		await wrapper.vm.addException()
		expect(postMock).not.toHaveBeenCalled()
		expect(wrapper.vm.error).not.toBe('')
	})

	it('creates a number and refreshes', async () => {
		postMock.mockResolvedValue({ data: { ocs: { data: { status: 'created' } } } })
		const wrapper = createWrapper()
		wrapper.vm.phone = '+254712345678'
		// Wait for the watcher to populate region, MNO and provider defaults.
		await new Promise((resolve) => setTimeout(resolve, 0))

		await wrapper.vm.addException()

		expect(postMock).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/phone-overrides', {
			phone: '+254712345678',
			mno: 'safaricom',
			provider: 'daraja',
		})
		expect(showSuccessMock).toHaveBeenCalled()
		expect(getMock).toHaveBeenCalled()
	})

	it('shows an error toast on a duplicate (409)', async () => {
		postMock.mockRejectedValue({ response: { status: 409 } })
		const wrapper = createWrapper()
		wrapper.vm.phone = '+254712345678'
		await new Promise((resolve) => setTimeout(resolve, 0))

		await wrapper.vm.addException()

		expect(showErrorMock).toHaveBeenCalled()
	})

	it('toggles active state via PATCH', async () => {
		patchMock.mockResolvedValue({ data: { ocs: { data: {} } } })
		const wrapper = createWrapper()
		const item = { ...OVERRIDE, active: true }

		await wrapper.vm.toggleActive(item)

		expect(patchMock).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/phone-overrides/1', { active: false })
		expect(item.active).toBe(false)
	})

	it('edits the phone number via PATCH and refreshes', async () => {
		patchMock.mockResolvedValue({ data: { ocs: { data: { status: 'updated' } } } })
		const wrapper = createWrapper()
		wrapper.vm.startEdit(OVERRIDE)
		expect(wrapper.vm.editingId).toBe(1)
		wrapper.vm.editPhone = '+254799000111'
		await new Promise((resolve) => setTimeout(resolve, 0))

		await wrapper.vm.saveEdit(OVERRIDE)

		expect(patchMock).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/phone-overrides/1', {
			phone: '+254799000111',
			mno: 'safaricom',
			provider: 'daraja',
		})
		expect(showSuccessMock).toHaveBeenCalled()
		expect(getMock).toHaveBeenCalled()
	})

	it('deletes a number via DELETE and refreshes', async () => {
		deleteMock.mockResolvedValue({ data: { ocs: { data: { success: true } } } })
		const wrapper = createWrapper()

		await wrapper.vm.remove(OVERRIDE)

		expect(deleteMock).toHaveBeenCalledWith('/apps/libresign/api/v1/admin/phone-overrides/1')
		expect(showSuccessMock).toHaveBeenCalled()
		expect(getMock).toHaveBeenCalled()
	})
})
