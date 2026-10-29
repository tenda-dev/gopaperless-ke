/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const axiosPostMock = vi.fn()
const confirmPasswordMock = vi.fn(() => Promise.resolve())
const loadStateMock = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: axiosPostMock } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path: string) => path }))
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: () => confirmPasswordMock() }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

let Component: unknown

beforeAll(async () => {
	;({ default: Component } = await import('../../../views/Settings/AdminOperationsPeriodReports.vue'))
})

const NEXT = {
	dueAt: '2026-11-02T06:00:00Z',
	periodFrom: '2026-10-26',
	periodTo: '2026-11-01',
	timezone: 'Africa/Nairobi',
}

function mountWith(state: Record<string, unknown>) {
	loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => key in state ? state[key] : fallback)
	return mount(Component as never, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: { template: '<div class="switch-stub"><slot /></div>' },
				NcSelect: { name: 'NcSelect', props: ['inputId'], template: '<div class="select-stub" :data-id="inputId" />' },
				NcButton: { name: 'NcButton', emits: ['click'], template: '<button class="button-stub" @click="$emit(\'click\')"><slot /></button>' },
			},
		},
	})
}

const enabledState = {
	operations_email_reports_admin_enabled: true,
	operations_email_reports_admin_schedule: { frequency: 'weekly', weekday: 1, dayOfMonth: 1, time: '09:00', timezone: 'Africa/Nairobi' },
	operations_email_reports_admin_next: NEXT,
}

describe('AdminOperationsPeriodReports settings', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
		axiosPostMock.mockResolvedValue({ data: { ocs: { data: { next: NEXT } } } })
		confirmPasswordMock.mockClear()
	})

	it('defaults to disabled with no fields, no unsaved bar and no next line', async () => {
		const wrapper = mountWith({})
		await flushPromises()
		const vm = wrapper.vm as any
		expect(vm.enabled).toBe(false)
		expect(vm.frequency).toBe('weekly')
		expect(vm.time).toBe('09:00')
		expect(vm.timezone).toBe('Africa/Nairobi')
		expect(wrapper.find('.select-stub').exists()).toBe(false)
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(false)
		expect(vm.nextSummary).toBe('')
	})

	it('renders from the initial state', async () => {
		const wrapper = mountWith({
			...enabledState,
			operations_email_reports_admin_schedule: { frequency: 'monthly', weekday: 1, dayOfMonth: 15, time: '07:30', timezone: 'Europe/Berlin' },
		})
		await flushPromises()
		const vm = wrapper.vm as any
		expect(vm.enabled).toBe(true)
		expect(vm.frequency).toBe('monthly')
		expect(vm.dayOfMonth).toBe(15)
		expect(vm.time).toBe('07:30')
		expect(vm.timezone).toBe('Europe/Berlin')
		expect((wrapper.find('input[type="time"]').element as HTMLInputElement).value).toBe('07:30')
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(false)
	})

	it('shows the Day control that follows the frequency', async () => {
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any
		const ids = () => wrapper.findAll('.select-stub').map((el) => el.attributes('data-id'))

		expect(ids()).toContain('operations-email-weekday')
		expect(ids()).not.toContain('operations-email-day-of-month')

		vm.frequency = 'monthly'
		await flushPromises()
		expect(ids()).toContain('operations-email-day-of-month')
		expect(ids()).not.toContain('operations-email-weekday')

		vm.frequency = 'daily'
		await flushPromises()
		expect(ids()).not.toContain('operations-email-weekday')
		expect(ids()).not.toContain('operations-email-day-of-month')
	})

	it('summarises unsaved changes and discard restores the saved values', async () => {
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any

		vm.frequency = 'daily'
		vm.time = '18:00'
		await flushPromises()
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(true)
		expect(vm.changes).toHaveLength(1)
		expect(vm.changes[0]).toContain('Weekly')
		expect(vm.changes[0]).toContain('Daily')

		vm.discard()
		await flushPromises()
		expect(vm.frequency).toBe('weekly')
		expect(vm.time).toBe('09:00')
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(false)
	})

	it('does not count an irrelevant field (weekday while daily) as a change', async () => {
		const wrapper = mountWith({
			...enabledState,
			operations_email_reports_admin_schedule: { frequency: 'daily', weekday: 1, dayOfMonth: 1, time: '09:00', timezone: 'Africa/Nairobi' },
		})
		await flushPromises()
		const vm = wrapper.vm as any
		vm.weekday = 5
		await flushPromises()
		expect(vm.isDirty).toBe(false)
	})

	it('posts {enabled, schedule} to the new endpoint after password confirmation and shows the server next run', async () => {
		const wrapper = mountWith({ operations_email_reports_admin_enabled: false })
		await flushPromises()
		const vm = wrapper.vm as any
		vm.enabled = true
		vm.frequency = 'weekly'
		vm.weekday = 3
		vm.time = '10:15'
		vm.timezone = 'Europe/Berlin'
		await flushPromises()

		await vm.save()
		await flushPromises()

		expect(confirmPasswordMock).toHaveBeenCalledTimes(1)
		expect(axiosPostMock).toHaveBeenCalledTimes(1)
		expect(axiosPostMock).toHaveBeenCalledWith('apps/libresign/api/v1/admin/operations-email-reports/config', {
			enabled: true,
			schedule: { frequency: 'weekly', weekday: 3, dayOfMonth: 1, time: '10:15', timezone: 'Europe/Berlin' },
		})
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(false)
		expect(vm.nextSummary).toContain('2026-10-26')
		expect(vm.nextSummary).toContain('2026-11-01')
	})

	it('refuses to save an invalid time while enabled', async () => {
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any
		vm.time = ''
		await flushPromises()
		await vm.save()
		expect(axiosPostMock).not.toHaveBeenCalled()
	})

	it('keeps the draft and shows an error when saving fails', async () => {
		axiosPostMock.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any
		vm.frequency = 'daily'
		await flushPromises()
		await vm.save()
		await flushPromises()
		expect(wrapper.find('.period-reports__error').exists()).toBe(true)
		expect(wrapper.find('.period-reports__unsaved').exists()).toBe(true)
		expect(vm.frequency).toBe('daily')
	})

	it('shows the server-provided Next report line only while enabled', async () => {
		const enabledWrapper = mountWith(enabledState)
		await flushPromises()
		expect((enabledWrapper.vm as any).nextSummary).toContain('2026-10-26')

		const disabledWrapper = mountWith({ operations_email_reports_admin_enabled: false, operations_email_reports_admin_next: NEXT })
		await flushPromises()
		expect((disabledWrapper.vm as any).nextSummary).toBe('')
	})

	it('sends a test email without saving, confirming a password or touching the draft', async () => {
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any

		await vm.sendTest()

		expect(axiosPostMock).toHaveBeenCalledTimes(1)
		expect(axiosPostMock).toHaveBeenCalledWith('apps/libresign/api/v1/admin/operations-email-reports/test', { email: '' })
		expect(confirmPasswordMock).not.toHaveBeenCalled()
		expect(vm.testMessage).toBe('Test email sent.')
		expect(vm.testError).toBe('')
		expect(vm.isDirty).toBe(false)
		expect(vm.testing).toBe(false)
	})

	it('sends the typed address, trimmed, and validates it client-side', async () => {
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any

		vm.testEmail = 'not-an-address'
		expect(vm.testEmailIsValid).toBe(false)
		vm.testEmail = '  qa@example.org '
		expect(vm.testEmailIsValid).toBe(true)
		await vm.sendTest()

		expect(axiosPostMock).toHaveBeenCalledWith('apps/libresign/api/v1/admin/operations-email-reports/test', { email: 'qa@example.org' })
	})

	it('shows the test section only while reports are enabled', async () => {
		const off = mountWith({})
		await flushPromises()
		expect(off.find('.period-reports__test').exists()).toBe(false)

		const on = mountWith(enabledState)
		await flushPromises()
		expect(on.find('.period-reports__test').exists()).toBe(true)
		expect(on.find('input[type="email"]').exists()).toBe(true)
	})

	it('shows the server reason when the test email fails', async () => {
		axiosPostMock.mockRejectedValue({ response: { data: { ocs: { data: { error: 'Your account has no email address.' } } } } })
		const wrapper = mountWith(enabledState)
		await flushPromises()
		const vm = wrapper.vm as any

		await vm.sendTest()

		expect(vm.testError).toBe('Your account has no email address.')
		expect(vm.testMessage).toBe('')
		expect(vm.testing).toBe(false)
	})

	it('falls back to a generic message when the failure has no server detail', async () => {
		axiosPostMock.mockRejectedValue(new Error('network'))
		const wrapper = mountWith({})
		await flushPromises()
		const vm = wrapper.vm as any

		await vm.sendTest()

		expect(vm.testError).toBe('The test email could not be sent.')
	})
})
