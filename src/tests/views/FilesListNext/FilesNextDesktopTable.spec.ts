/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import FilesNextDesktopTable from '../../../views/FilesListNext/components/FilesNextDesktopTable.vue'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'
import type { Column } from '../../../views/FilesListNext/composables/useSorting.ts'

const toggleSortByMock = vi.fn()

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

vi.mock('../../../store/filesSorting.js', () => ({
	useFilesSortingStore: vi.fn(() => ({
		toggleSortBy: toggleSortByMock,
		sortingMode: 'name',
		sortingDirection: 'asc',
	})),
}))

vi.mock('../../../store/userconfig.js', () => ({
	useUserConfigStore: vi.fn(() => ({
		files_list_show_signers: true,
	})),
}))

const columns: Column[] = [
	{ id: 'name', label: 'Document' },
	{ id: 'status', label: 'Status' },
	{ id: 'signers', label: 'Signers' },
	{ id: 'created', label: 'Created' },
]

function makeRow(overrides: Partial<FilesNextRow> = {}): FilesNextRow {
	return {
		key: 'uuid-1',
		id: 1,
		nodeId: 17,
		uuid: 'uuid-1',
		name: 'contract.pdf',
		ext: 'pdf',
		requester: 'Alice',
		statusCode: 1,
		statusLabel: 'Pending',
		signersTotal: 2,
		signersSigned: 1,
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
} = {}) {
	return mount(FilesNextDesktopTable, {
		props: {
			columns,
			displayRows: props.displayRows ?? [makeRow()],
			selectedIds: props.selectedIds ?? new Set<number | string>(),
			isMobile: false,
		},
	})
}

describe('FilesNextDesktopTable.vue', () => {
	beforeEach(() => {
		toggleSortByMock.mockReset()
	})

	it('renders one table row per display row', () => {
		const wrapper = createWrapper({
			displayRows: [makeRow(), makeRow({ key: 'uuid-2', id: 2, uuid: 'uuid-2', name: 'second.pdf' })],
		})

		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(wrapper.text()).toContain('contract.pdf')
		expect(wrapper.text()).toContain('second.pdf')
	})

	it('emits select-all from the header checkbox', async () => {
		const wrapper = createWrapper()

		await wrapper.find('thead .col-check input[type="checkbox"]').trigger('change')

		expect(wrapper.emitted('select-all')).toHaveLength(1)
	})

	it('emits toggle-one from a row checkbox', async () => {
		const wrapper = createWrapper()

		await wrapper.find('tbody .col-check input[type="checkbox"]').trigger('change')

		expect(wrapper.emitted('toggle-one')).toEqual([[1]])
	})

	it('marks selected rows and checks their checkbox', () => {
		const wrapper = createWrapper({ selectedIds: new Set([1]) })
		const row = wrapper.find('tbody tr')

		expect(row.classes()).toContain('is-selected')
		expect((wrapper.find('tbody .col-check input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true)
	})

	it('emits open from the name button', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('.files-next__name-btn').trigger('click')

		expect(wrapper.emitted('open')).toEqual([[row]])
	})

	it('emits kebab from the row actions button', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('.files-next__kebab').trigger('click')

		expect(wrapper.emitted('kebab')?.[0][1]).toEqual(row)
	})

	it('emits context-menu on row right-click', async () => {
		const row = makeRow()
		const wrapper = createWrapper({ displayRows: [row] })

		await wrapper.find('tbody tr').trigger('contextmenu')

		expect(wrapper.emitted('context-menu')?.[0][1]).toEqual(row)
	})

	it('drives server-side sorting from the column headers', async () => {
		const wrapper = createWrapper()

		await wrapper.findAll('.files-next__sort')[1].trigger('click')

		expect(toggleSortByMock).toHaveBeenCalledWith('status')
	})

	it('renders the signers fraction when signers exist', () => {
		const wrapper = createWrapper()

		expect(wrapper.find('.files-next__signers-num').text()).toBe('1')
		expect(wrapper.find('.files-next__signers-den').text()).toBe('/ 2')
	})
})
