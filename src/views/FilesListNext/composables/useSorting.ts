/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Server-side sorting + the visible column set for the Files list.
 *
 * Sorting is SERVER-SIDE: clicking a header drives the filesSorting store,
 * which persists the choice and triggers a store refetch
 * (libresign:sorting:update). List column ids map to the backend sort keys.
 */
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'

import { useFilesSortingStore } from '../../../store/filesSorting.js'
import { useUserConfigStore } from '../../../store/userconfig.js'

export type ColId = 'name' | 'status' | 'signers' | 'created'
export type Column = { id: ColId, label: string }

/** List column id → backend sort key. Frozen (static, never mutated). */
const SORT_MODE: Readonly<Record<ColId, string>> = Object.freeze({
	name: 'name',
	status: 'status',
	signers: 'signers',
	created: 'created_at',
})

const ALL_COLUMNS: readonly Column[] = Object.freeze([
	{ id: 'name', label: t('libresign', 'Document') },
	{ id: 'status', label: t('libresign', 'Status') },
	{ id: 'signers', label: t('libresign', 'Signers') },
	{ id: 'created', label: t('libresign', 'Created') },
]) as readonly Column[]

export function useSorting() {
	const filesSortingStore = useFilesSortingStore()
	const userConfigStore = useUserConfigStore()

	// Admin/user toggle (default true) controls whether the Signers column shows.
	const showSigners = computed(() => userConfigStore.files_list_show_signers !== false)
	const columns = computed<Column[]>(() => ALL_COLUMNS.filter((c) => c.id !== 'signers' || showSigners.value))

	function onSort(id: ColId) {
		filesSortingStore.toggleSortBy(SORT_MODE[id])
	}
	function isSortedBy(id: ColId): boolean {
		return filesSortingStore.sortingMode === SORT_MODE[id]
	}
	function sortDirOf(id: ColId): 'asc' | 'desc' {
		return filesSortingStore.sortingDirection === 'asc' ? 'asc' : 'desc'
	}

	return { showSigners, columns, onSort, isSortedBy, sortDirOf }
}
