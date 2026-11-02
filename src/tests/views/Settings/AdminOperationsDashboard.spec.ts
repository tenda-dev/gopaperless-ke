/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const axiosGetMock = vi.fn()
const axiosPostMock = vi.fn(() => Promise.resolve({ data: { ocs: { data: {} } } }))
const confirmPasswordMock = vi.fn(() => Promise.resolve())
const loadStateMock = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGetMock, post: axiosPostMock } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path: string) => path }))
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: () => confirmPasswordMock() }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

let Component: unknown

beforeAll(async () => {
	;({ default: Component } = await import('../../../views/Settings/AdminOperationsDashboard.vue'))
})

function mountWith(state: Record<string, unknown>) {
	loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => key in state ? state[key] : fallback)
	return mount(Component as never, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: { template: '<div class="switch-stub"><slot /></div>' },
				NcSelect: { name: 'NcSelect', template: '<div class="select-stub" />' },
			},
		},
	})
}

describe('AdminOperationsDashboard settings', () => {
	beforeEach(() => {
		axiosGetMock.mockReset()
		axiosGetMock.mockResolvedValue({ data: { ocs: { data: { groups: [{ id: 'ops', displayname: 'Ops team' }] } } } })
		axiosPostMock.mockClear()
		confirmPasswordMock.mockClear()
	})

	it('defaults to disabled and hides the group picker', async () => {
		const wrapper = mountWith({})
		await flushPromises()
		expect((wrapper.vm as any).enabled).toBe(false)
		expect(wrapper.find('.select-stub').exists()).toBe(false)
	})

	it('shows the picker when enabled and resolves saved group display names', async () => {
		const wrapper = mountWith({ operations_dashboard_admin_enabled: true, operations_dashboard_admin_groups: ['ops'] })
		await flushPromises()
		expect(wrapper.find('.select-stub').exists()).toBe(true)
		expect((wrapper.vm as any).groupsSelected).toEqual([{ id: 'ops', displayname: 'Ops team' }])
	})

	it('saves flag and group ids after password confirmation', async () => {
		const wrapper = mountWith({ operations_dashboard_admin_enabled: true, operations_dashboard_admin_groups: ['ops'] })
		await flushPromises()
		await (wrapper.vm as any).save()
		expect(confirmPasswordMock).toHaveBeenCalledTimes(1)
		expect(axiosPostMock).toHaveBeenCalledWith('apps/libresign/api/v1/admin/operations-dashboard/config', {
			enabled: true,
			groups: ['ops'],
		})
	})
})
