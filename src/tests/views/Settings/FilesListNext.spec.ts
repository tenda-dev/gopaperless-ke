/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'

import FilesListNext from '../../../views/Settings/FilesListNext.vue'

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

const OCP = {
	AppConfig: {
		setValue: vi.fn(),
	},
}

;(globalThis as typeof globalThis & { OCP: typeof OCP }).OCP = OCP

function createWrapper() {
	return mount(FilesListNext, {
		global: {
			stubs: {
				NcSettingsSection: { template: '<div><slot /></div>' },
				NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
			},
		},
	})
}

describe('FilesListNext.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('defaults to disabled (legacy files list) when no state is provided', () => {
		const wrapper = createWrapper()

		expect(wrapper.vm.enabled).toBe(false)
	})

	it('loads enabled state from initial state', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'files_list_next_enabled') return true
			return fallback
		})

		const wrapper = createWrapper()

		expect(wrapper.vm.enabled).toBe(true)
	})

	it('saves enabled state when the switch is toggled', async () => {
		const wrapper = createWrapper()
		const switchBtn = wrapper.findComponent(NcCheckboxRadioSwitchStub)

		await switchBtn.vm.$emit('update:modelValue', true)
		await wrapper.vm.saveEnabled()

		expect(OCP.AppConfig.setValue).toHaveBeenCalledWith('libresign', 'files_list_next_enabled', '1')
	})
})
