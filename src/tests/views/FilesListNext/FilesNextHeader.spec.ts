/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import FilesNextHeader from '../../../views/FilesListNext/components/FilesNextHeader.vue'

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

const defaultProps = {
	isMobile: false,
	selectMode: false,
	selectedCount: 0,
	rowCount: 3,
	canRequestSign: true,
	loading: false,
}

function createWrapper(props: Partial<typeof defaultProps> = {}) {
	return mount(FilesNextHeader, {
		props: { ...defaultProps, ...props },
		global: {
			stubs: {
				FilesNextFilters: { template: '<div class="filters-stub" />' },
			},
		},
	})
}

describe('FilesNextHeader.vue', () => {
	it('renders the title and the document count', () => {
		const wrapper = createWrapper({ rowCount: 3 })

		expect(wrapper.find('.files-next__title').text()).toBe('Documents')
		expect(wrapper.find('.files-next__subtitle').text()).toBe('3 documents')
	})

	it('uses the singular form for a single document', () => {
		const wrapper = createWrapper({ rowCount: 1 })

		expect(wrapper.find('.files-next__subtitle').text()).toBe('1 document')
	})

	it('emits refresh from the reload control', async () => {
		const wrapper = createWrapper()

		await wrapper.find('.files-next__ctl').trigger('click')

		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})

	it('disables the reload control while loading', () => {
		const wrapper = createWrapper({ loading: true })

		expect(wrapper.find('.files-next__ctl').attributes('disabled')).toBeDefined()
	})

	it('emits new from the New button when signing can be requested', async () => {
		const wrapper = createWrapper()

		await wrapper.find('.files-next__new').trigger('click')

		expect(wrapper.emitted('new')).toHaveLength(1)
	})

	it('hides the New button when signing cannot be requested', () => {
		const wrapper = createWrapper({ canRequestSign: false })

		expect(wrapper.find('.files-next__new').exists()).toBe(false)
	})

	it('swaps in the selection bar on mobile select mode', () => {
		const wrapper = createWrapper({ isMobile: true, selectMode: true, selectedCount: 2 })

		expect(wrapper.find('.files-next__header').exists()).toBe(false)
		expect(wrapper.find('.files-next__selbar').exists()).toBe(true)
		expect(wrapper.find('.files-next__selbar-count').text()).toBe('2 selected')
	})

	it('selection bar emits exit-select, download and delete', async () => {
		const wrapper = createWrapper({ isMobile: true, selectMode: true, selectedCount: 2 })
		const buttons = wrapper.findAll('.files-next__selbar button')

		// close, download, delete (no view button for multi-selection)
		expect(buttons).toHaveLength(3)
		await buttons[0].trigger('click')
		await buttons[1].trigger('click')
		await buttons[2].trigger('click')

		expect(wrapper.emitted('exit-select')).toHaveLength(1)
		expect(wrapper.emitted('download')).toHaveLength(1)
		expect(wrapper.emitted('delete')).toHaveLength(1)
	})

	it('shows the view button only for a single selection', async () => {
		const wrapper = createWrapper({ isMobile: true, selectMode: true, selectedCount: 1 })
		const buttons = wrapper.findAll('.files-next__selbar button')

		// close, view, download, delete
		expect(buttons).toHaveLength(4)
		await buttons[1].trigger('click')

		expect(wrapper.emitted('view')).toHaveLength(1)
	})

	it('disables download and delete when nothing is selected', () => {
		const wrapper = createWrapper({ isMobile: true, selectMode: true, selectedCount: 0 })
		const buttons = wrapper.findAll('.files-next__selbar button')

		expect(buttons[1].attributes('disabled')).toBeDefined()
		expect(buttons[2].attributes('disabled')).toBeDefined()
	})
})
