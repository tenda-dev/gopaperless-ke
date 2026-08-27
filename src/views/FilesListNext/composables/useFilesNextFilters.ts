/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Native status + modified-date filters for the Files list. Owns only the
 * presentation (dropdown open state, anchor, local selections) and commits
 * straight to the existing filters store, which persists the choice and emits
 * `libresign:filters:update` → the files store refetches.
 */
import { computed, reactive, ref } from 'vue'

import { useFiltersStore } from '../../../store/filters.js'
import { FILE_STATUS } from '../../../constants.js'
import { getStatusLabel } from '../../../utils/fileStatus.js'
import { getTimePresets } from '../../../utils/timePresets.js'
import { statusVariant } from './rowFormatters.ts'

export type FilterChip = { id: string | number, text: string, onclick: () => void }
type TimePreset = { id: string, label: string, start: number, end: number }
type FilterName = 'status' | 'modified'

/** The four filterable statuses (static). */
const STATUS_OPTIONS = Object.freeze(
	[FILE_STATUS.DRAFT, FILE_STATUS.ABLE_TO_SIGN, FILE_STATUS.PARTIAL_SIGNED, FILE_STATUS.SIGNED]
		.map((id) => ({ id, label: getStatusLabel(id), variant: statusVariant(id) })),
)

export function useFilesNextFilters() {
	const filtersStore = useFiltersStore()

	const activeFilterCount = computed(() => filtersStore.activeChips.length)
	const activeChips = computed(() => filtersStore.activeChips as FilterChip[])

	// Panels are teleported to <body> and positioned from the trigger's rect, so
	// no ancestor overflow/stacking context can clip them.
	const openFilter = ref<FilterName | null>(null)
	const filterAnchor = reactive({ x: 0, y: 0 })

	function toggleFilter(name: FilterName, e: MouseEvent) {
		if (openFilter.value === name) {
			openFilter.value = null
			return
		}
		const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
		const panelWidth = 240
		filterAnchor.x = Math.max(8, Math.min(r.left, window.innerWidth - panelWidth - 8))
		filterAnchor.y = r.bottom + 6
		openFilter.value = name
	}

	function closeFilter() {
		openFilter.value = null
	}

	const statusOptions = STATUS_OPTIONS
	const timePresets = getTimePresets() as TimePreset[]

	// Keep status selection store-backed so filters applied outside the dropdown,
	// such as dashboard stat cards, stay synchronized with the filter UI.
	const statusSelected = computed<number[]>(() => filtersStore.filterStatusArray)
	const modifiedSelected = ref<string | null>(filtersStore.filter_modified || null)

	// Restore chips for a persisted status filter so it is visible immediately
	// in the filter UI without persisting or refetching it again.
	if (filtersStore.filterStatusArray.length > 0
		&& !(filtersStore.chips.status && filtersStore.chips.status.length)) {
		filtersStore.applyStatusFilter([...filtersStore.filterStatusArray], { persist: false, emit: false })
	}

	function toggleStatus(id: number) {
		const current = filtersStore.filterStatusArray as number[]
		const next = current.includes(id)
			? current.filter((s) => s !== id)
			: [...current, id]
		filtersStore.applyStatusFilter(next)
	}

	function commitModified() {
		const preset = timePresets.find((p) => p.id === modifiedSelected.value)
		const chips = preset
			? [{ id: preset.id, start: preset.start, end: preset.end, text: preset.label, onclick: () => { modifiedSelected.value = null; commitModified() } }]
			: []
		filtersStore.onFilterUpdateChipsAndSave({ detail: chips, id: 'modified' })
	}

	function selectModified(id: string) {
		modifiedSelected.value = modifiedSelected.value === id ? null : id
		commitModified()
		openFilter.value = null
	}

	return {
		activeFilterCount,
		activeChips,
		openFilter,
		filterAnchor,
		toggleFilter,
		closeFilter,
		statusOptions,
		timePresets,
		statusSelected,
		modifiedSelected,
		toggleStatus,
		selectModified,
	}
}
