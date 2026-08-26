<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - The "Files" list for GoPaperless — served at /f/filelist/sign (name: fileslist).
  - The previous implementation is kept at /f/filelist/legacy.
  -
  - ONE foundation, TWO presentations. This view is the conductor only: it wires
  - the composables (rows, sorting, selection, breakpoint, pagination, actions,
  - menu, dialog) and hands presentation to the child components:
  -       - desktop (>768px): <FilesNextDesktopTable> + anchored teleported menu
  -       - mobile  (<=768px): <FilesNextMobileList> + bottom sheet + long-press
-->
<template>
	<div class="files-next" :class="{ 'files-next--mobile': isMobile }">
		<!-- Floating desktop selection pill -->
		<FilesNextSelectionPill :show="!isMobile && selectedIds.size > 0" :count="selectedIds.size"
			:has-single="!!selectedRow" :can-download="canPillDownload" :can-delete="canPillDelete"
			@view="viewSelected" @download="pillDownload" @delete="pillDelete" @clear="clearSelection" />

		<!-- Header: title + count + controls (mobile select mode swaps in the selection bar) -->
		<FilesNextHeader :is-mobile="isMobile" :select-mode="selectMode" :selected-count="selectedIds.size"
			:row-count="rows.length" :can-request-sign="canRequestSign" :loading="filesStore.loading"
			@refresh="refresh" @new="goNew" @exit-select="exitSelectMode" @view="viewSelected"
			@download="bulkDownload" @delete="bulkDelete" @clear="clearSelection" />

		<!-- Active filter chips -->
		<FilesNextFilterChips v-if="!isMobile || !selectMode" />

		<!-- Scrollable body -->
		<div ref="scroller" class="files-next__body">

			<!-- Skeleton shimmer during the first load — replaces the spinner -->
			<FilesNextSkeleton v-if="isInitialLoading" :mobile="isMobile" />

			<!-- Real content — fades in once loaded -->
			<template v-else>
				<FilesNextDesktopTable v-if="!isMobile" :columns="columns" :display-rows="displayRows"
					:selected-ids="selectedIds" :is-mobile="isMobile"
					@toggle-one="toggleOne" @select-all="toggleSelectAll" @context-menu="onRowContextMenu"
					@kebab="onKebab" @open="openFile" />

				<FilesNextMobileList v-else :display-rows="displayRows" :selected-ids="selectedIds"
					:select-mode="selectMode" @row-tap="onMobileRowTap" @press-start="onPressStart"
					@press-move="onPressMove" @press-end="onPressEnd" @kebab="onKebab" @sign="signRow" />

				<div v-if="!filesStore.loading && displayRows.length === 0" class="files-next__empty">
					{{ activeFilterCount > 0 ? t('libresign', 'No documents match the filters') : t('libresign', 'There are no documents') }}
				</div>
			</template>

			<!-- Pagination indicator (subsequent pages only; initial load uses the skeleton) -->
			<div v-if="filesStore.loading && displayRows.length > 0" class="files-next__loading">{{ t('libresign',
				'Loading …')
				}}</div>

			<div ref="sentinel" class="files-next__sentinel" aria-hidden="true" />
		</div>

		<!-- Row action surface (desktop menu + mobile bottom sheet) -->
		<FilesNextRowMenu :open="menu.open" :row="menu.row" :items="menuItems" :x="menu.x" :y="menu.y"
			:mobile="isMobile" @close="closeMenu" @select="runAction" />

		<!-- Confirmation / rename dialog — every confirm-worthy action routes here -->
		<FilesNextConfirmDialog :open="dialog.open" :name="dialogTitle" :message="dialogMessage"
			:kind="dialog.kind" :buttons="dialogButtons" v-model:rename-value="renameValue"
			v-model:delete-file-too="deleteFileToo"
			@update:open="(v) => { if (!v) { closeDialog() } }" @submit="confirmDialog" />
	</div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { t } from '@nextcloud/l10n'

import FilesNextConfirmDialog from './components/FilesNextConfirmDialog.vue'
import FilesNextDesktopTable from './components/FilesNextDesktopTable.vue'
import FilesNextFilterChips from './components/FilesNextFilterChips.vue'
import FilesNextHeader from './components/FilesNextHeader.vue'
import FilesNextMobileList from './components/FilesNextMobileList.vue'
import FilesNextRowMenu from './components/FilesNextRowMenu.vue'
import FilesNextSelectionPill from './components/FilesNextSelectionPill.vue'
import FilesNextSkeleton from './components/FilesNextSkeleton.vue'
import { useFilesNextRows, type FilesNextRow } from './useFilesNextRows.ts'
import { useBreakpoint } from './composables/useBreakpoint.ts'
import { useFilesNextActions } from './composables/useFilesNextActions.ts'
import { useFilesNextDialog } from './composables/useFilesNextDialog.ts'
import { useFilesNextMenu } from './composables/useFilesNextMenu.ts'
import { usePagination } from './composables/usePagination.ts'
import { useRowSelection } from './composables/useRowSelection.ts'
import { useSorting } from './composables/useSorting.ts'
import { useFiltersStore } from '../../store/filters.js'
import { useSidebarStore } from '../../store/sidebar.js'
import { useSignStore } from '../../store/sign.js'

defineOptions({ name: 'FilesListNext' })

defineProps<{ loading?: boolean }>()
const emit = defineEmits<{ (e: 'update:loading', v: boolean): void }>()

const router = useRouter()
const { filesStore, rows } = useFilesNextRows()
const sidebarStore = useSidebarStore()
const signStore = useSignStore()

const canRequestSign = computed(() => filesStore.canRequestSign)

// Server-side sorting + the visible column set.
const { columns } = useSorting()

// Filtering UI lives in <FilesNextFilters> / <FilesNextFilterChips>; the list
// only needs the active count for the empty-state copy.
const filtersStore = useFiltersStore()
const activeFilterCount = computed(() => filtersStore.activeChips.length)

// The store already returns rows in server sort order.
const displayRows = computed<FilesNextRow[]>(() => rows.value)

/**
 * Initial load only (no rows yet) → skeleton. Pagination keeps the current list.
 */
const isInitialLoading = computed(() => filesStore.loading && displayRows.value.length === 0)

/**
 * Pagination + reload + New + deep-link. `scroller` stays here because the
 * delete-collapse animation queries rows within it.
 */
const scroller = ref<HTMLElement | null>(null)
const sentinel = ref<HTMLElement | null>(null)

/**
 * Selection — a Set of ids OUTSIDE the rows, plus mobile long-press select
 * and the pill's single-selection helpers. Action dispatch is injected from
 * the action/dialog composables below (closures resolve post-setup).
 */
const {
	selectedIds, toggleOne, toggleSelectAll, clearSelection,
	bulkDelete, bulkDownload, selectedRow, canPillDownload, canPillDelete, viewSelected, pillDownload, pillDelete,
	selectMode, exitSelectMode, clearPressTimer, onPressStart, onPressMove, onPressEnd, onMobileRowTap,
} = useRowSelection({
	displayRows,
	filesStore,
	sidebarStore,
	openFile: (row) => actions.openFile(row),
	downloadRow: (row) => actions.downloadRow(row),
	deleteRow: (row) => actions.deleteRow(row),
	openBulkDelete: () => dialogApi.openDialog('bulkDelete', null),
})

// Breakpoint: leave mobile select mode when growing back to desktop.
const { isMobile } = useBreakpoint((mobile) => { if (!mobile) { exitSelectMode() } })

/**
 * Confirmation / rename dialogs — every confirm-worthy action routes here.
 */
const dialogApi = useFilesNextDialog({
	filesStore,
	scroller,
	displayRows,
	selectedIds,
	onBulkDeleted: () => { clearSelection(); exitSelectMode() },
})
const {
	dialog, renameValue, deleteFileToo, closeDialog, confirmDialog, dialogTitle, dialogMessage, dialogButtons,
} = dialogApi

/**
 * Action dispatchers — reuse EXISTING store/router semantics.
 */
const actions = useFilesNextActions({
	router,
	filesStore,
	sidebarStore,
	signStore,
	openDialog: dialogApi.openDialog,
	renameValue,
})
const { openFile, signRow } = actions

/**
 * Action surface: desktop menu / mobile sheet (shared map)
 */
const { menu, menuItems, onKebab, onRowContextMenu, closeMenu, runAction } = useFilesNextMenu({ filesStore, actions })

function onKeydown(e: KeyboardEvent) {
	// Menu/filter Esc are handled inside their own components; here we only
	// exit mobile select mode.
	if (e.key === 'Escape' && selectMode.value) {
		exitSelectMode()
	}
}

const { refresh, goNew } = usePagination({
	filesStore,
	router,
	sidebarStore,
	sentinel,
	onFiltersChanged: () => { clearSelection(); exitSelectMode() },
	onInitialLoaded: () => emit('update:loading', false),
})

onMounted(() => {
	document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
	document.removeEventListener('keydown', onKeydown)
	clearPressTimer()
	// Drop any active filters so they don't leak into the next visit to the list
	// (e.g. opening it from the sidebar after a dashboard stat-card deep link).
	filtersStore.resetFilters()
})
</script>

<style scoped lang="scss">
.files-next {
	position: relative; // anchor for the floating selection pill overlay
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	padding: 8px 24px 0;
	box-sizing: border-box;
	color: var(--color-main-text);

	&--mobile {
		padding: 8px 12px 0;
	}

	// Body
	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
	}

	&__empty,
	&__loading {
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 40px;
		color: var(--color-text-maxcontrast);
	}

	&__sentinel {
		height: 1px;
	}
}
</style>
