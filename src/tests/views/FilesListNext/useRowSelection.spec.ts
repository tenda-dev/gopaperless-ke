/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import { useRowSelection } from '../../../views/FilesListNext/composables/useRowSelection.ts'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

function row(id: number): FilesNextRow {
	return { key: `k${id}`, id, nodeType: 'file', raw: {} } as unknown as FilesNextRow
}

function setup() {
	const rows = [row(1), row(2)]
	const openFile = vi.fn()
	const sel = useRowSelection({
		displayRows: computed(() => rows),
		filesStore: { isOriginalFileDeleted: () => false, canDelete: () => true, selectFile: vi.fn() },
		sidebarStore: { activeRequestSignatureTab: vi.fn() },
		openFile,
		downloadRow: vi.fn(),
		deleteRow: vi.fn(),
		openBulkDelete: vi.fn(),
	})
	return { rows, openFile, ...sel }
}

describe('useRowSelection mobile tap', () => {
	it('a normal tap invokes the injected row-opening callback', () => {
		const { rows, openFile, onMobileRowTap, selectedIds } = setup()

		onMobileRowTap(rows[0])

		expect(openFile).toHaveBeenCalledWith(rows[0])
		expect(selectedIds.value.size).toBe(0)
	})

	it('a tap in select mode only toggles selection', () => {
		const { rows, openFile, onMobileRowTap, selectedIds, selectMode } = setup()
		selectMode.value = true

		onMobileRowTap(rows[0])
		expect(selectedIds.value.has(1)).toBe(true)
		onMobileRowTap(rows[0])
		expect(selectedIds.value.has(1)).toBe(false)

		expect(openFile).not.toHaveBeenCalled()
	})

	it('the click following a long-press is suppressed (no open, no toggle-off)', () => {
		vi.useFakeTimers()
		const { rows, openFile, onMobileRowTap, onPressStart, onPressEnd, selectMode, selectedIds } = setup()

		onPressStart({ clientX: 0, clientY: 0 } as PointerEvent, rows[0])
		vi.advanceTimersByTime(500)
		onPressEnd()
		expect(selectMode.value).toBe(true)
		expect(selectedIds.value.has(1)).toBe(true)

		onMobileRowTap(rows[0]) // the click that follows the press
		expect(openFile).not.toHaveBeenCalled()
		expect(selectedIds.value.has(1)).toBe(true)

		onMobileRowTap(rows[1]) // next tap is a normal select-mode toggle
		expect(selectedIds.value.has(2)).toBe(true)
		expect(openFile).not.toHaveBeenCalled()

		vi.useRealTimers()
	})
})
