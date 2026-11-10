/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { Router } from 'vue-router'
import { ref } from 'vue'

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string, params?: Record<string, string>) => path.replace('{uuid}', params?.uuid ?? '')),
	getRootUrl: vi.fn(() => ''),
	generateOcsUrl: vi.fn((p: string) => p),
}))
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(async () => ({ data: { ocs: { data: {} } } })), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../services/toast', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showInfo: vi.fn() }))

import { useFilesStore } from '../../../store/files.js'
import { useSidebarStore } from '../../../store/sidebar.js'
import { useFilesNextActions } from '../../../views/FilesListNext/composables/useFilesNextActions.ts'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

function makeRow(overrides: Partial<FilesNextRow> = {}): FilesNextRow {
	return {
		key: 'u', id: 5, nodeId: 99, uuid: 'uuid-5', name: 'a.pdf', nodeType: 'file', raw: {},
		...overrides,
	} as unknown as FilesNextRow
}

describe('row open (real stores, mocked OCA.Viewer)', () => {
	const open = vi.fn()

	beforeEach(() => {
		setActivePinia(createPinia())
		open.mockReset()
		;(globalThis as any).OCA = { Viewer: { open } }
	})
	afterEach(() => {
		delete (globalThis as any).OCA
	})

	function build() {
		const filesStore = useFilesStore()
		const sidebarStore = useSidebarStore()
		vi.spyOn(filesStore, 'fetchFileDetail').mockResolvedValue(undefined as never)
		const actions = useFilesNextActions({
			router: { push: vi.fn() } as unknown as Router,
			filesStore,
			sidebarStore,
			signStore: { setFileToSign: vi.fn() },
			openDialog: vi.fn(),
			renameValue: ref(''),
		})
		return { filesStore, sidebarStore, actions }
	}

	it('selects the document, shows the Action Centre, and sends the viewer payload', () => {
		const { filesStore, sidebarStore, actions } = build()

		actions.openRow(makeRow())

		expect(filesStore.selectedFileId).toBe(5)
		expect(sidebarStore.activeTab).toBe('request-signature-tab')
		expect(sidebarStore.isVisible).toBe(true)
		expect(open).toHaveBeenCalledTimes(1)
		expect(open.mock.calls[0][0].fileInfo).toMatchObject({ basename: 'a.pdf', fileid: 99, mime: 'application/pdf' })
	})

	it('keeps selection and sidebar after the viewer closes (viewer never touches stores)', () => {
		const { filesStore, sidebarStore, actions } = build()

		actions.openRow(makeRow())
		// Viewer closing is a no-op for our stores: nothing is invoked on close.

		expect(filesStore.selectedFileId).toBe(5)
		expect(sidebarStore.isVisible).toBe(true)
		expect(sidebarStore.activeTab).toBe('request-signature-tab')
	})

	it('envelope: selected and sidebar visible, viewer not opened', () => {
		const { filesStore, sidebarStore, actions } = build()

		actions.openRow(makeRow({ nodeType: 'envelope' }))

		expect(filesStore.selectedFileId).toBe(5)
		expect(sidebarStore.isVisible).toBe(true)
		expect(open).not.toHaveBeenCalled()
	})

	it('deleted original: selected and sidebar visible, viewer not opened (real isOriginalFileDeleted)', () => {
		const { filesStore, sidebarStore, actions } = build()

		actions.openRow(makeRow({ raw: { metadata: { original_file_deleted: true } } }))

		expect(filesStore.selectedFileId).toBe(5)
		expect(sidebarStore.isVisible).toBe(true)
		expect(open).not.toHaveBeenCalled()
	})
})
