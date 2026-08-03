/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Row action surface state for the Files list: the anchored desktop menu /
 * mobile bottom sheet. Owns the open state and anchor coordinates, builds the
 * per-row item list from the store's own permission helpers, and dispatches
 * the selected item through the injected action dispatchers.
 */
import { computed, reactive } from 'vue'

import {
	mdiDownloadOutline,
	mdiEyeOutline,
	mdiFolderOutline,
	mdiPencilOutline,
	mdiSignature,
	mdiTextBoxCheck,
	mdiTrashCanOutline,
} from '@mdi/js'

import { t } from '@nextcloud/l10n'

import type { MenuItem } from '../components/FilesNextRowMenu.vue'
import type { FilesNextRow } from '../useFilesNextRows.ts'
import type { FilesNextActions } from './useFilesNextActions.ts'

type FilesStoreLike = {
	isOriginalFileDeleted: (raw: unknown) => boolean
	canValidate: (raw: unknown) => boolean
	canSign: (raw: unknown) => boolean
	canDelete: (raw: unknown) => boolean
}

type Options = {
	filesStore: FilesStoreLike
	/** Action dispatchers owned by useFilesNextActions. */
	actions: FilesNextActions
}

export function useFilesNextMenu(opts: Options) {
	const { filesStore, actions } = opts

	const menu = reactive<{ open: boolean, row: FilesNextRow | null, x: number, y: number }>({
		open: false, row: null, x: 0, y: 0,
	})

	const menuItems = computed<MenuItem[]>(() => {
		const row = menu.row
		if (!row) {
			return []
		}
		const raw = row.raw
		const items: MenuItem[] = []

		// View — opens the right sidebar (document action centre); matches the pill.
		// Consolidates the former "Details" / "Request signature" items (same action).
		items.push({ id: 'view', label: t('libresign', 'View'), icon: mdiEyeOutline, run: () => actions.openDetails(row) })
		if (row.nodeType !== 'envelope' && !filesStore.isOriginalFileDeleted(raw)) {
			items.push({ id: 'open', label: t('libresign', 'Open'), icon: mdiFolderOutline, run: () => actions.openFile(row) })
		}
		items.push({ id: 'download', label: t('libresign', 'Download'), icon: mdiDownloadOutline, run: () => actions.downloadRow(row) })
		items.push({ id: 'rename', label: t('libresign', 'Rename'), icon: mdiPencilOutline, run: () => actions.renameRow(row) })
		if (filesStore.canValidate(raw)) {
			items.push({ id: 'validate', label: t('libresign', 'Validate'), icon: mdiTextBoxCheck, run: () => actions.validateRow(row) })
		}
		if (filesStore.canSign(raw)) {
			items.push({ id: 'sign', label: t('libresign', 'Sign'), icon: mdiSignature, run: () => actions.signRow(row) })
		}
		if (filesStore.canDelete(raw)) {
			items.push({ id: 'delete', label: t('libresign', 'Delete'), icon: mdiTrashCanOutline, danger: true, run: () => actions.deleteRow(row) })
		}
		return items
	})

	function openMenuAt(row: FilesNextRow, x: number, y: number) {
		// Positioning/clamping and the sheet drag live inside <FilesNextRowMenu>.
		menu.row = row
		menu.x = x
		menu.y = y
		menu.open = true
	}

	function onRowContextMenu(e: MouseEvent, row: FilesNextRow) {
		e.preventDefault()
		openMenuAt(row, e.clientX, e.clientY)
	}

	function onKebab(e: MouseEvent, row: FilesNextRow) {
		const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
		openMenuAt(row, r.left, r.bottom + 4)
	}

	function closeMenu() {
		menu.open = false
		menu.row = null
	}

	async function runAction(item: MenuItem) {
		closeMenu()
		await item.run()
	}

	return { menu, menuItems, openMenuAt, onKebab, onRowContextMenu, closeMenu, runAction }
}
