/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowSignatureSetup from '../../../../components/RightSidebar/Workflow/WorkflowSignatureSetup.vue'
import { FILE_STATUS } from '../../../../constants'

vi.mock('@nextcloud/l10n', () => createL10nMock())

const file = (extra: Record<string, unknown> = {}) => ({
	status: FILE_STATUS.DRAFT ?? 0,
	visibleElements: [{ signRequestId: 1 }],
	signers: [{ signed: false }],
	...extra,
})

function mountSetup(props: Record<string, unknown>) {
	return mount(WorkflowSignatureSetup, {
		props: props as any,
		global: {
			stubs: {
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template: '<button class="btn" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
				},
				NcLoadingIcon: { template: '<span class="loading-icon" />' },
				NcIconSvgWrapper: { props: ['path'], template: '<span class="svg-icon" />' },
			},
		},
	})
}

describe('WorkflowSignatureSetup.vue (S2)', () => {
	afterEach(() => vi.restoreAllMocks())

	it('shows the pencil icon and an enabled button when not loading', () => {
		const wrapper = mountSetup({ file: file(), isLoading: false })
		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		expect(wrapper.find('.svg-icon').exists()).toBe(true)
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('shows a spinner and disables the button while loading', () => {
		const wrapper = mountSetup({ file: file(), isLoading: true })
		expect(wrapper.find('.loading-icon').exists()).toBe(true)
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
	})

	it('re-enables the button when loading clears (retry possible after failure)', async () => {
		const wrapper = mountSetup({ file: file(), isLoading: true })
		await wrapper.setProps({ isLoading: false })
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('edit-positions')).toHaveLength(1)
	})

	it('does not emit from a disabled button', async () => {
		const wrapper = mountSetup({ file: file(), isLoading: true })
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('edit-positions')).toBeUndefined()
	})

	it.each([FILE_STATUS.SIGNED, FILE_STATUS.SIGNING_IN_PROGRESS])('keeps the blocked behaviour (no button) for status %s', (status) => {
		const wrapper = mountSetup({ file: file({ status }), isLoading: false })
		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.find('.workflow-signature-positions__locked').exists()).toBe(true)
	})

	it('does not write to the console', () => {
		const spy = vi.spyOn(console, 'log').mockImplementation(() => {})
		mountSetup({ file: file(), isLoading: false })
		expect(spy).not.toHaveBeenCalled()
	})
})
