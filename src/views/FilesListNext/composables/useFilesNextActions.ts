/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Action dispatchers for the Files list — reuse the EXISTING store/router
 * semantics (viewer, sidebar tabs, sign flow, validation route). Destructive
 * or confirm-worthy actions don't run here: they route to the dialog
 * composable via the injected `openDialog`.
 */
import type { Ref } from 'vue'
import type { Router } from 'vue-router'

import { generateUrl } from '@nextcloud/router'

import type { FilesNextRow } from '../useFilesNextRows.ts'
import type { DialogKind } from './useFilesNextDialog.ts'
import { getSigningRouteUuid } from '../../../utils/signRequestUuid.ts'
import { openDocument } from '../../../utils/viewer.js'

type SidebarStoreLike = {
	activeRequestSignatureTab: () => void
	activeSignTab: () => void
	hideSidebar: () => void
}
type SignStoreLike = { setFileToSign: (file: unknown) => void }

type Options = {
	router: Router
	/** The files Pinia store (loosely typed to avoid duplicating its shape). */
	filesStore: any
	sidebarStore: SidebarStoreLike
	signStore: SignStoreLike
	/** Dialog entry point owned by useFilesNextDialog. */
	openDialog: (kind: DialogKind, row: FilesNextRow | null) => void
	/** Rename field state owned by useFilesNextDialog (primed before opening). */
	renameValue: Ref<string>
}

export function useFilesNextActions(opts: Options) {
	const { router, filesStore, sidebarStore, signStore } = opts

	function openFile(row: FilesNextRow) {
		if (row.nodeType === 'envelope') {
			return
		}
		const fileUrl = row.raw?.file || generateUrl('/apps/libresign/p/pdf/{uuid}', { uuid: row.uuid })
		openDocument({ fileUrl, filename: row.name, nodeId: row.nodeId ?? 0 })
	}

	function downloadRow(row: FilesNextRow) {
		const url = row.raw?.file || generateUrl('/apps/libresign/p/pdf/{uuid}', { uuid: row.uuid })
		window.open(url, '_blank', 'noopener')
	}

	function openDetails(row: FilesNextRow) {
		filesStore.selectFile(row.id as number)
		sidebarStore.activeRequestSignatureTab()
	}

	async function signRow(row: FilesNextRow) {
		const detailed = await filesStore.fetchFileDetail({ fileId: row.id as number, force: true })
		const uuid = getSigningRouteUuid(detailed)
		if (!uuid || !detailed) {
			return
		}
		filesStore.selectFile(row.id as number)
		signStore.setFileToSign(detailed)
		await router.push({ name: 'SignPDF', params: { uuid } })
		sidebarStore.activeSignTab()
	}

	function validateRow(row: FilesNextRow) {
		if (!row.uuid) {
			return
		}
		sidebarStore.hideSidebar()
		router.push({ name: 'ValidationFile', params: { uuid: row.uuid } })
	}

	function deleteRow(row: FilesNextRow) {
		opts.openDialog('delete', row)
	}

	function renameRow(row: FilesNextRow) {
		if (!row.uuid) {
			return
		}
		opts.renameValue.value = row.name
		opts.openDialog('rename', row)
	}

	return { openFile, downloadRow, openDetails, signRow, validateRow, deleteRow, renameRow }
}

export type FilesNextActions = ReturnType<typeof useFilesNextActions>
