/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import type { Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useFilesNextActions } from '../../../views/FilesListNext/composables/useFilesNextActions.ts'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

const openDocumentMock = vi.fn()
const routerPushMock = vi.fn(async () => undefined)

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string, params?: Record<string, string>) => path.replace('{uuid}', params?.uuid ?? '')),
}))

vi.mock('../../../utils/viewer.js', () => ({
	openDocument: (...args: unknown[]) => openDocumentMock(...args),
}))

const { showErrorMock } = vi.hoisted(() => ({ showErrorMock: vi.fn() }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

vi.mock('../../../services/toast', () => ({
	showError: (...args: unknown[]) => showErrorMock(...args),
	showSuccess: vi.fn(),
	showInfo: vi.fn(),
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
		raw: {},
		...overrides,
	}
}

function createActions() {
	const filesStore = {
		selectFile: vi.fn(),
		isOriginalFileDeleted: vi.fn((raw: any) => !!raw?.metadata?.original_file_deleted),
		fetchFileDetail: vi.fn(async (): Promise<any> => ({
			id: 1,
			uuid: 'uuid-1',
			signers: [{ me: true, sign_uuid: 'sign-uuid-1' }],
			settings: { isApprover: false },
		})),
	}
	const sidebarStore = {
		activeRequestSignatureTab: vi.fn(),
		activeSignTab: vi.fn(),
		hideSidebar: vi.fn(),
	}
	const signStore = { setFileToSign: vi.fn() }
	const openDialog = vi.fn()
	const renameValue = ref('')
	const actions = useFilesNextActions({
		router: { push: routerPushMock } as unknown as Router,
		filesStore,
		sidebarStore,
		signStore,
		openDialog,
		renameValue,
	})
	return { filesStore, sidebarStore, signStore, openDialog, renameValue, ...actions }
}

describe('useFilesNextActions', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('openFile opens the viewer with the document URL', () => {
		const { openFile } = createActions()

		openFile(makeRow())

		expect(openDocumentMock).toHaveBeenCalledWith({
			fileUrl: '/apps/libresign/p/pdf/uuid-1',
			filename: 'contract.pdf',
			nodeId: 17,
		})
	})

	it('openFile ignores envelopes', () => {
		const { openFile } = createActions()

		openFile(makeRow({ nodeType: 'envelope' }))

		expect(openDocumentMock).not.toHaveBeenCalled()
	})

	describe('openRow', () => {
		it('selects the row, activates the Action Centre, then opens the viewer in that order', () => {
			const { openRow, filesStore, sidebarStore } = createActions()

			openRow(makeRow({ id: 42 }))

			expect(filesStore.selectFile).toHaveBeenCalledWith(42)
			expect(sidebarStore.activeRequestSignatureTab).toHaveBeenCalledTimes(1)
			expect(openDocumentMock).toHaveBeenCalledWith({
				fileUrl: '/apps/libresign/p/pdf/uuid-1',
				filename: 'contract.pdf',
				nodeId: 17,
			})
			const select = filesStore.selectFile.mock.invocationCallOrder[0]
			const sidebar = sidebarStore.activeRequestSignatureTab.mock.invocationCallOrder[0]
			const viewer = openDocumentMock.mock.invocationCallOrder[0]
			expect(select).toBeLessThan(sidebar)
			expect(sidebar).toBeLessThan(viewer)
		})

		it('selects an envelope and activates the sidebar without opening the viewer', () => {
			const { openRow, filesStore, sidebarStore } = createActions()

			openRow(makeRow({ id: 7, nodeType: 'envelope' }))

			expect(filesStore.selectFile).toHaveBeenCalledWith(7)
			expect(sidebarStore.activeRequestSignatureTab).toHaveBeenCalledTimes(1)
			expect(openDocumentMock).not.toHaveBeenCalled()
		})

		it('selects a deleted original and activates the sidebar without opening the viewer', () => {
			const { openRow, filesStore, sidebarStore } = createActions()

			openRow(makeRow({ id: 8, raw: { metadata: { original_file_deleted: true } } }))

			expect(filesStore.isOriginalFileDeleted).toHaveBeenCalledWith({ metadata: { original_file_deleted: true } })
			expect(filesStore.selectFile).toHaveBeenCalledWith(8)
			expect(sidebarStore.activeRequestSignatureTab).toHaveBeenCalledTimes(1)
			expect(openDocumentMock).not.toHaveBeenCalled()
		})

		it('never hides the sidebar or activates another tab', () => {
			const { openRow, sidebarStore } = createActions()

			openRow(makeRow())
			openRow(makeRow({ nodeType: 'envelope' }))

			expect(sidebarStore.hideSidebar).not.toHaveBeenCalled()
			expect(sidebarStore.activeSignTab).not.toHaveBeenCalled()
		})

		it('openFile stays viewer-only (no selection, no sidebar)', () => {
			const { openFile, filesStore, sidebarStore } = createActions()

			openFile(makeRow())

			expect(openDocumentMock).toHaveBeenCalledTimes(1)
			expect(filesStore.selectFile).not.toHaveBeenCalled()
			expect(sidebarStore.activeRequestSignatureTab).not.toHaveBeenCalled()
		})
	})

	it('downloadRow opens the file URL in a new tab', () => {
		const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
		const { downloadRow } = createActions()

		downloadRow(makeRow())

		expect(openSpy).toHaveBeenCalledWith('/apps/libresign/p/pdf/uuid-1', '_blank', 'noopener')
		openSpy.mockRestore()
	})

	it('openDetails selects the file and opens the request-signature tab', () => {
		const { filesStore, sidebarStore, openDetails } = createActions()

		openDetails(makeRow())

		expect(filesStore.selectFile).toHaveBeenCalledWith(1)
		expect(sidebarStore.activeRequestSignatureTab).toHaveBeenCalledTimes(1)
	})

	it('signRow loads the detail, routes to SignPDF and activates the sign tab', async () => {
		const { filesStore, sidebarStore, signStore, signRow } = createActions()

		await signRow(makeRow())

		expect(filesStore.fetchFileDetail).toHaveBeenCalledWith({ fileId: 1, force: true })
		expect(filesStore.selectFile).toHaveBeenCalledWith(1)
		expect(signStore.setFileToSign).toHaveBeenCalledTimes(1)
		expect(routerPushMock).toHaveBeenCalledWith({ name: 'SignPDF', params: { uuid: 'sign-uuid-1' } })
		expect(sidebarStore.activeSignTab).toHaveBeenCalledTimes(1)
	})

	it('validateRow hides the sidebar and routes to the validation view', () => {
		const { sidebarStore, validateRow } = createActions()

		validateRow(makeRow())

		expect(sidebarStore.hideSidebar).toHaveBeenCalledTimes(1)
		expect(routerPushMock).toHaveBeenCalledWith({ name: 'ValidationFile', params: { uuid: 'uuid-1' } })
	})

	it('validateRow is a no-op without a uuid', () => {
		const { validateRow } = createActions()

		validateRow(makeRow({ uuid: null }))

		expect(routerPushMock).not.toHaveBeenCalled()
	})

	it('deleteRow routes to the delete dialog', () => {
		const { openDialog, deleteRow } = createActions()
		const row = makeRow()

		deleteRow(row)

		expect(openDialog).toHaveBeenCalledWith('delete', row)
	})

	it('renameRow primes the field and routes to the rename dialog', () => {
		const { openDialog, renameValue, renameRow } = createActions()
		const row = makeRow()

		renameRow(row)

		expect(renameValue.value).toBe('contract.pdf')
		expect(openDialog).toHaveBeenCalledWith('rename', row)
	})

	it('renameRow is a no-op without a uuid', () => {
		const { openDialog, renameRow } = createActions()

		renameRow(makeRow({ uuid: null }))

		expect(openDialog).not.toHaveBeenCalled()
	})

	it('signRow shows an error and does not navigate when no signer uuid resolves', async () => {
		const { filesStore, signStore, signRow } = createActions()
		filesStore.fetchFileDetail.mockImplementation(async () => ({
			id: 1,
			uuid: 'uuid-1',
			signers: [{ me: true }],
			settings: { isApprover: false },
		}))

		await signRow(makeRow())

		expect(showErrorMock).toHaveBeenCalledTimes(1)
		expect(routerPushMock).not.toHaveBeenCalled()
		expect(signStore.setFileToSign).not.toHaveBeenCalled()
	})

	it('signRow does not substitute the file uuid for a missing signer uuid', async () => {
		const { filesStore, signRow } = createActions()
		filesStore.fetchFileDetail.mockImplementation(async () => ({
			id: 1,
			uuid: 'file-uuid',
			signers: [{ me: true }],
			settings: { isApprover: false },
		}))

		await signRow(makeRow({ uuid: 'file-uuid' }))

		expect(routerPushMock).not.toHaveBeenCalled()
	})

	it('signRow shows an error when fetchFileDetail rejects', async () => {
		const { filesStore, signRow } = createActions()
		filesStore.fetchFileDetail.mockImplementation(async () => {
			throw new Error('network')
		})

		await signRow(makeRow())

		expect(showErrorMock).toHaveBeenCalledTimes(1)
		expect(routerPushMock).not.toHaveBeenCalled()
	})
})
