/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Selection for the Files list — a Set of ids held OUTSIDE the rows (survives
 * scroll), plus the mobile long-press multi-select mechanics and the pill's
 * single-selection helpers. The actual open/download/delete dispatch is injected
 * so this stays purely about selection state.
 */
import { computed, ref, type ComputedRef } from 'vue'

import type { FilesNextRow } from '../useFilesNextRows.ts'

type FilesStoreLike = {
	isOriginalFileDeleted: (raw: unknown) => boolean
	canDelete: (raw: unknown) => boolean
	selectFile: (id?: number) => void
}
type SidebarStoreLike = { activeRequestSignatureTab: () => void }

type Options = {
	displayRows: ComputedRef<FilesNextRow[]>
	filesStore: FilesStoreLike
	sidebarStore: SidebarStoreLike
	/** Injected action dispatchers (owned by the orchestrator). */
	openFile: (row: FilesNextRow) => void
	downloadRow: (row: FilesNextRow) => void
	deleteRow: (row: FilesNextRow) => void
	openBulkDelete: () => void
}

const LONG_PRESS_MS = 430
const MOVE_CANCEL_PX = 10

export function useRowSelection(opts: Options) {
	const { displayRows, filesStore, sidebarStore } = opts

	const selectedIds = ref<Set<number | string>>(new Set())

	function toggleOne(id: number | string) {
		const next = new Set(selectedIds.value)
		next.has(id) ? next.delete(id) : next.add(id)
		selectedIds.value = next
	}
	const allVisibleSelected = computed(() =>
		displayRows.value.length > 0 && displayRows.value.every((r) => selectedIds.value.has(r.id)),
	)
	const someVisibleSelected = computed(() => displayRows.value.some((r) => selectedIds.value.has(r.id)))
	function toggleSelectAll() {
		const next = new Set(selectedIds.value)
		if (allVisibleSelected.value) {
			displayRows.value.forEach((r) => next.delete(r.id))
		} else {
			displayRows.value.forEach((r) => next.add(r.id))
		}
		selectedIds.value = next
	}
	function clearSelection() {
		selectedIds.value = new Set()
	}

	/* ---------- Bulk ---------- */
	function bulkDelete() {
		if (selectedIds.value.size === 0) {
			return
		}
		opts.openBulkDelete()
	}
	function bulkDownload() {
		displayRows.value.filter((r) => selectedIds.value.has(r.id)).forEach(opts.downloadRow)
	}

	/* ---------- Single-selection (pill) ---------- */
	const selectedRow = computed<FilesNextRow | null>(() => {
		if (selectedIds.value.size !== 1) {
			return null
		}
		const id = [...selectedIds.value][0]
		return displayRows.value.find((r) => r.id === id) ?? null
	})
	// Download/open only makes sense for a real, openable document (envelopes can't be opened).
	const canPillDownload = computed(() => {
		const r = selectedRow.value
		return !!r && r.nodeType !== 'envelope' && !filesStore.isOriginalFileDeleted(r.raw)
	})
	// Delete visibility follows the store's own permission (own presentation, not domain).
	const canPillDelete = computed(() => {
		const r = selectedRow.value
		return !!r && filesStore.canDelete(r.raw)
	})
	function viewSelected() {
		const r = selectedRow.value
		if (!r) {
			return
		}
		filesStore.selectFile(r.id as number)
		sidebarStore.activeRequestSignatureTab()
		selectMode.value = false
		clearSelection()
	}
	function pillDownload() {
		if (canPillDownload.value && selectedRow.value) {
			opts.downloadRow(selectedRow.value)
		}
	}
	function pillDelete() {
		if (canPillDelete.value && selectedRow.value) {
			opts.deleteRow(selectedRow.value)
		}
	}

	/* ---------- Mobile multi-select (long-press) ---------- */
	const selectMode = ref(false)
	let pressTimer: number | null = null
	let pressStart = { x: 0, y: 0 }
	let suppressClick = false

	function enterSelectMode(row: FilesNextRow) {
		selectMode.value = true
		toggleOne(row.id)
		suppressClick = true
		if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
			navigator.vibrate(10)
		}
	}
	function exitSelectMode() {
		selectMode.value = false
		clearSelection()
	}
	function clearPressTimer() {
		if (pressTimer !== null) {
			window.clearTimeout(pressTimer)
			pressTimer = null
		}
	}
	function onPressStart(e: PointerEvent, row: FilesNextRow) {
		if (selectMode.value) {
			return
		}
		pressStart = { x: e.clientX, y: e.clientY }
		pressTimer = window.setTimeout(() => enterSelectMode(row), LONG_PRESS_MS)
	}
	function onPressMove(e: PointerEvent) {
		if (pressTimer === null) {
			return
		}
		if (Math.abs(e.clientX - pressStart.x) > MOVE_CANCEL_PX || Math.abs(e.clientY - pressStart.y) > MOVE_CANCEL_PX) {
			clearPressTimer()
		}
	}
	function onPressEnd() {
		clearPressTimer()
	}
	function onMobileRowTap(row: FilesNextRow) {
		if (suppressClick) {
			suppressClick = false
			return
		}
		if (selectMode.value) {
			toggleOne(row.id)
			return
		}
		opts.openFile(row)
	}

	return {
		selectedIds,
		toggleOne,
		allVisibleSelected,
		someVisibleSelected,
		toggleSelectAll,
		clearSelection,
		bulkDelete,
		bulkDownload,
		selectedRow,
		canPillDownload,
		canPillDelete,
		viewSelected,
		pillDownload,
		pillDelete,
		selectMode,
		exitSelectMode,
		clearPressTimer,
		onPressStart,
		onPressMove,
		onPressEnd,
		onMobileRowTap,
	}
}
