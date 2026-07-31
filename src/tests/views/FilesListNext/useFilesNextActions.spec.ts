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
		fetchFileDetail: vi.fn(async () => ({
			id: 1,
			uuid: 'uuid-1',
			signers: [{ me: true, sign_request_uuid: 'sign-uuid-1' }],
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
})
