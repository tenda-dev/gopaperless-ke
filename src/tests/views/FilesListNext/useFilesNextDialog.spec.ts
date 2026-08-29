/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useFilesNextDialog } from '../../../views/FilesListNext/composables/useFilesNextDialog.ts'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

const showSuccessMock = vi.fn()
const showErrorMock = vi.fn()

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

vi.mock('../../../services/toast', () => ({
	showSuccess: (...args: unknown[]) => showSuccessMock(...args),
	showError: (...args: unknown[]) => showErrorMock(...args),
}))

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
		raw: { id: 1, uuid: 'uuid-1' },
		...overrides,
	}
}

function createDialog(rows: FilesNextRow[] = [makeRow()], selected: Set<number | string> = new Set()) {
	const filesStore = {
		delete: vi.fn(async () => undefined),
		rename: vi.fn(async () => true),
	}
	const onBulkDeleted = vi.fn()
	const dialogApi = useFilesNextDialog({
		filesStore,
		scroller: ref<HTMLElement | null>(null),
		displayRows: computed(() => rows),
		selectedIds: ref(selected),
		onBulkDeleted,
	})
	return { filesStore, onBulkDeleted, ...dialogApi }
}

describe('useFilesNextDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('openDialog sets kind/row and defaults deleteFileToo for deletions', () => {
		const { dialog, deleteFileToo, openDialog } = createDialog()
		const row = makeRow()
		deleteFileToo.value = false

		openDialog('delete', row)

		expect(dialog).toMatchObject({ open: true, kind: 'delete', row })
		expect(deleteFileToo.value).toBe(true)
	})

	it('closeDialog resets the state', () => {
		const { dialog, openDialog, closeDialog } = createDialog()

		openDialog('rename', makeRow())
		closeDialog()

		expect(dialog).toMatchObject({ open: false, kind: null, row: null })
	})

	it('derives title/message/buttons from the kind', () => {
		const { dialogTitle, dialogMessage, dialogButtons, openDialog } = createDialog()

		openDialog('rename', makeRow())
		expect(dialogTitle.value).toBe('Rename file')
		expect(dialogMessage.value).toBe('')
		expect(dialogButtons.value.map((b) => b.label)).toEqual(['Cancel', 'Rename'])

		openDialog('bulkDelete', null)
		expect(dialogTitle.value).toBe('Confirm')
		expect(dialogMessage.value).toContain('selected file(s)')
		expect(dialogButtons.value.map((b) => b.label)).toEqual(['Cancel', 'Delete'])
	})

	it('confirm delete removes the document and shows a success toast', async () => {
		const { filesStore, dialog, openDialog, confirmDialog } = createDialog()
		const row = makeRow()

		openDialog('delete', row)
		await confirmDialog()

		expect(filesStore.delete).toHaveBeenCalledWith(row.raw, true)
		expect(showSuccessMock).toHaveBeenCalledWith('Document deleted')
		expect(dialog.open).toBe(false)
	})

	it('confirm delete surfaces store failures as an error toast', async () => {
		const { filesStore, openDialog, confirmDialog } = createDialog()
		filesStore.delete.mockRejectedValueOnce(new Error('nope'))

		openDialog('delete', makeRow())
		await confirmDialog()

		expect(showErrorMock).toHaveBeenCalledWith('Could not delete the document')
	})

	it('confirm bulk delete removes every selected row and resets selection', async () => {
		const rows = [makeRow(), makeRow({ key: 'uuid-2', id: 2, uuid: 'uuid-2', raw: { id: 2 } })]
		const { filesStore, onBulkDeleted, openDialog, confirmDialog } = createDialog(rows, new Set([1, 2]))

		openDialog('bulkDelete', null)
		await confirmDialog()

		expect(filesStore.delete).toHaveBeenCalledTimes(2)
		expect(showSuccessMock).toHaveBeenCalledWith('2 documents deleted')
		expect(onBulkDeleted).toHaveBeenCalledTimes(1)
	})

	it('confirm rename renames only when the name changed', async () => {
		const { filesStore, renameValue, openDialog, confirmDialog } = createDialog()
		const row = makeRow()

		openDialog('rename', row)
		renameValue.value = 'contract.pdf'
		await confirmDialog()
		expect(filesStore.rename).not.toHaveBeenCalled()

		openDialog('rename', row)
		renameValue.value = 'renamed.pdf'
		await confirmDialog()
		expect(filesStore.rename).toHaveBeenCalledWith('uuid-1', 'renamed.pdf')
		expect(showSuccessMock).toHaveBeenCalledWith('Renamed "contract.pdf" to "renamed.pdf"')
	})
})
