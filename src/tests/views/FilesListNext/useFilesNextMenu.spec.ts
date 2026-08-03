/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useFilesNextMenu } from '../../../views/FilesListNext/composables/useFilesNextMenu.ts'
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
		requester: '',
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

function createMenu(overrides: Partial<ReturnType<typeof createDeps>> = {}) {
	const deps = createDeps()
	Object.assign(deps.filesStore, overrides.filesStore ?? {})
	const menuApi = useFilesNextMenu({ filesStore: deps.filesStore, actions: deps.actions })
	return { ...deps, ...menuApi }
}

function createDeps() {
	return {
		filesStore: {
			isOriginalFileDeleted: vi.fn(() => false),
			canValidate: vi.fn(() => true),
			canSign: vi.fn(() => true),
			canDelete: vi.fn(() => true),
		},
		actions: {
			openFile: vi.fn(),
			downloadRow: vi.fn(),
			openDetails: vi.fn(),
			signRow: vi.fn(),
			validateRow: vi.fn(),
			deleteRow: vi.fn(),
			renameRow: vi.fn(),
		},
	}
}

describe('useFilesNextMenu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('starts closed with no items', () => {
		const { menu, menuItems } = createMenu()

		expect(menu.open).toBe(false)
		expect(menu.row).toBeNull()
		expect(menuItems.value).toEqual([])
	})

	it('openMenuAt anchors the menu on a row', () => {
		const { menu, openMenuAt } = createMenu()
		const row = makeRow()

		openMenuAt(row, 10, 20)

		expect(menu).toMatchObject({ open: true, row, x: 10, y: 20 })
	})

	it('builds the full item list from store permissions', () => {
		const { menuItems, openMenuAt } = createMenu()

		openMenuAt(makeRow(), 0, 0)

		expect(menuItems.value.map((item) => item.id)).toEqual([
			'view', 'open', 'download', 'rename', 'validate', 'sign', 'delete',
		])
	})

	it('omits Open for envelopes and permission-gated items', () => {
		const deps = createDeps()
		deps.filesStore.canValidate.mockReturnValue(false)
		deps.filesStore.canSign.mockReturnValue(false)
		deps.filesStore.canDelete.mockReturnValue(false)
		const { menuItems, openMenuAt } = useFilesNextMenu({ filesStore: deps.filesStore, actions: deps.actions })

		openMenuAt(makeRow({ nodeType: 'envelope' }), 0, 0)

		expect(menuItems.value.map((item) => item.id)).toEqual(['view', 'download', 'rename'])
	})

	it('runAction closes the menu and runs the item', async () => {
		const deps = createDeps()
		const { menu, menuItems, openMenuAt, runAction } = useFilesNextMenu({ filesStore: deps.filesStore, actions: deps.actions })

		openMenuAt(makeRow(), 0, 0)
		const viewItem = menuItems.value.find((item) => item.id === 'view')!
		await runAction(viewItem)

		expect(menu.open).toBe(false)
		expect(menu.row).toBeNull()
		expect(deps.actions.openDetails).toHaveBeenCalledTimes(1)
	})

	it('closeMenu clears the open row', () => {
		const { menu, openMenuAt, closeMenu } = createMenu()

		openMenuAt(makeRow(), 0, 0)
		closeMenu()

		expect(menu.open).toBe(false)
		expect(menu.row).toBeNull()
	})
})
