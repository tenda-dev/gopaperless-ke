/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import FilesNextMobileList from '../../../views/FilesListNext/components/FilesNextMobileList.vue'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

function makeRow(overrides: Partial<FilesNextRow> = {}): FilesNextRow {
	return {
		key: 'uuid-1',
		id: 1,
		nodeId: 17,
		uuid: 'uuid-1',
		name: 'contract.pdf',
		ext: 'pdf',
		requester: 'alice@example.com',
		statusCode: 1,
		statusLabel: 'Pending',
		signersTotal: 0,
		signersSigned: 0,
		updated: null,
		created: null,
		canSign: true,
		signUuid: 'sign-uuid-1',
		nodeType: 'file',
		raw: {},
		...overrides,
	}
}

function createWrapper(props: {
	displayRows?: FilesNextRow[]
	selectedIds?: Set<number | string>
	selectMode?: boolean
} = {}) {
	return mount(FilesNextMobileList, {
		props: {
			displayRows: props.displayRows ?? [makeRow()],
			selectedIds: props.selectedIds ?? new Set<number | string>(),
			selectMode: props.selectMode ?? false,
		},
	})
}

describe('FilesNextMobileList.vue', () => {
	it('renders one card row per display row', () => {
		const wrapper = createWrapper({
			displayRows: [makeRow(), makeRow({ key: 'uuid-2', id: 2, uuid: 'uuid-2', name: 'second.pdf' })],
		})

		expect(wrapper.findAll('.m-row')).toHaveLength(2)
		expect(wrapper.text()).toContain('contract.pdf')
		expect(wrapper.text()).toContain('second.pdf')
	})

	it('emits row-tap when a row is clicked', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('.m-row').trigger('click')

		expect(wrapper.emitted('row-tap')).toEqual([[row]])
	})

	it('emits the long-press lifecycle events', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })
		const el = wrapper.find('.m-row')

		await el.trigger('pointerdown', { clientX: 10, clientY: 10 })
		await el.trigger('pointermove', { clientX: 12, clientY: 12 })
		await el.trigger('pointerup')

		expect(wrapper.emitted('press-start')?.[0][1]).toEqual(row)
		expect(wrapper.emitted('press-move')).toHaveLength(1)
		expect(wrapper.emitted('press-end')).toHaveLength(1)
	})

	it('emits sign from the inline Sign button', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('.m-sign').trigger('click')

		expect(wrapper.emitted('sign')).toEqual([[row]])
	})

	it('hides the Sign button when the row cannot be signed', () => {
		const wrapper = createWrapper({ displayRows: [makeRow({ canSign: false })] })

		expect(wrapper.find('.m-sign').exists()).toBe(false)
	})

	it('emits kebab from the row actions button', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('.m-kebab').trigger('click')

		expect(wrapper.emitted('kebab')?.[0][1]).toEqual(row)
	})

	it('shows selection state in select mode and hides row actions', () => {
		const wrapper = createWrapper({ selectMode: true, selectedIds: new Set([1]) })

		expect(wrapper.find('.m-check').exists()).toBe(true)
		expect(wrapper.find('.m-check--on').exists()).toBe(true)
		expect(wrapper.find('.m-row--selected').exists()).toBe(true)
		expect(wrapper.find('.m-actions').exists()).toBe(false)
	})
})
