/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Confirmation / rename dialog state for the Files list. Every confirm-worthy
 * action (delete, bulk delete, rename) routes through here: the composable
 * owns the dialog state, the rename/delete-file-too fields, the derived
 * title/message/buttons, and the confirm dispatch (including the row-collapse
 * animation before removal).
 */
import { computed, reactive, ref, type ComputedRef, type Ref } from 'vue'

import { n, t } from '@nextcloud/l10n'

import { showError, showSuccess } from '../../../services/toast'
import { animateRowRemoval } from '../utils/animateRowRemoval.ts'
import type { FilesNextRow } from '../useFilesNextRows.ts'

export type DialogKind = 'delete' | 'bulkDelete' | 'rename'

type Options = {
	/** The files Pinia store (loosely typed to avoid duplicating its shape). */
	filesStore: any
	/** Scroll container — the collapse animation queries rows within it. */
	scroller: Ref<HTMLElement | null>
	displayRows: ComputedRef<FilesNextRow[]>
	selectedIds: Ref<Set<number | string>>
	/** Called after a successful bulk delete (to drop selection state). */
	onBulkDeleted: () => void
}

export function useFilesNextDialog(opts: Options) {
	const { filesStore, scroller, displayRows, selectedIds } = opts

	const dialog = reactive<{ open: boolean, kind: DialogKind | null, row: FilesNextRow | null }>({
		open: false, kind: null, row: null,
	})

	const renameValue = ref('')
	// Delete removes the underlying file too by default (legacy parity); the dialog
	// lets the user opt to delete only the signature request.
	const deleteFileToo = ref(true)

	function openDialog(kind: DialogKind, row: FilesNextRow | null) {
		dialog.kind = kind
		dialog.row = row
		dialog.open = true
		if (kind === 'delete' || kind === 'bulkDelete') {
			deleteFileToo.value = true
		}
		// <FilesNextConfirmDialog> focuses the rename field itself when it opens.
	}

	function closeDialog() {
		dialog.open = false
		dialog.kind = null
		dialog.row = null
	}

	async function confirmDialog() {
		// Capture before closeDialog() resets the reactive state.
		const kind = dialog.kind
		const row = dialog.row
		const alsoDeleteFile = deleteFileToo.value
		closeDialog()
		if (kind === 'delete' && row) {
			try {
				await animateRowRemoval(scroller.value, row.id) // collapse the row before it leaves the store
				await filesStore.delete(row.raw, alsoDeleteFile)
				showSuccess(t('libresign', 'Document deleted'))
			} catch (error) {
				showError(t('libresign', 'Could not delete the document'))
			}
		} else if (kind === 'bulkDelete') {
			const targets = displayRows.value.filter((r) => selectedIds.value.has(r.id))
			try {
				await Promise.all(targets.map((r) => animateRowRemoval(scroller.value, r.id)))
				for (const r of targets) {
					await filesStore.delete(r.raw, alsoDeleteFile)
				}
				showSuccess(n('libresign', '{count} document deleted', '{count} documents deleted', targets.length, { count: targets.length }))
			} catch (error) {
				showError(t('libresign', 'Could not delete some documents'))
			}
			opts.onBulkDeleted()
		} else if (kind === 'rename' && row?.uuid) {
			const nextName = renameValue.value.trim()
			if (nextName && nextName !== row.name) {
				const oldName = row.name
				const ok = await filesStore.rename(row.uuid, nextName)
				if (ok) {
					showSuccess(t('libresign', 'Renamed "{oldName}" to "{newName}"', { oldName, newName: nextName }))
				} else {
					showError(t('libresign', 'Could not rename the document'))
				}
			}
		}
	}

	const dialogTitle = computed(() => (dialog.kind === 'rename' ? t('libresign', 'Rename file') : t('libresign', 'Confirm')))

	const dialogMessage = computed(() => {
		if (dialog.kind === 'delete') {
			return t('libresign', 'The signature request will be deleted. Do you confirm this action?')
		}
		if (dialog.kind === 'bulkDelete') {
			return t('libresign', 'Delete {count} selected file(s)? This cannot be undone.', { count: selectedIds.value.size })
		}
		return ''
	})

	const dialogButtons = computed(() => {
		if (dialog.kind === 'rename') {
			return [
				{ label: t('libresign', 'Cancel'), callback: closeDialog },
				{ label: t('libresign', 'Rename'), variant: 'primary' as const, callback: confirmDialog },
			]
		}
		return [
			{ label: t('libresign', 'Cancel'), callback: closeDialog },
			{ label: t('libresign', 'Delete'), variant: 'error' as const, callback: confirmDialog },
		]
	})

	return {
		dialog,
		renameValue,
		deleteFileToo,
		openDialog,
		closeDialog,
		confirmDialog,
		dialogTitle,
		dialogMessage,
		dialogButtons,
	}
}
