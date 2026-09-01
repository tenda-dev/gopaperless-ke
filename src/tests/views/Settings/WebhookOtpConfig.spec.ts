/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
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

function createWrapper() {
	return mount(WebhookOtpConfig as never, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: { template: '<div><slot /></div>' },
				NcTextField: true,
				NcPasswordField: true,
			},
		},
	})
}

describe('WebhookOtpConfig', () => {
	beforeEach(() => {
		loadStateMock.mockReset()
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => fallback)
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
})
