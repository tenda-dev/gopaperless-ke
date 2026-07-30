<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - The "Files" list for GoPaperless — served at /f/filelist/sign (name: fileslist).
  - The previous implementation is kept at /f/filelist/legacy.
  -
  - ONE foundation, TWO presentations. Standard HTML, styled with Nextcloud
  - design tokens. Reads the EXISTING `files` store READ-ONLY via a local
  - adapter composable and reuses the store's own action dispatchers.
  -   · Shared, breakpoint-agnostic: store adapter, selection Set, action map.
  -   · Differs by breakpoint: row template + action surface only
  -       - desktop (>768px): semantic <table> + anchored teleported menu
  -       - mobile  (<=768px): card rows + bottom sheet + long-press multi-select
-->
<template>
	<div class="files-next" :class="{ 'files-next--mobile': isMobile }">
		<!-- Floating desktop selection pill -->
		<FilesNextSelectionPill :show="!isMobile && selectedIds.size > 0" :count="selectedIds.size"
			:has-single="!!selectedRow" :can-download="canPillDownload" :can-delete="canPillDelete"
			@view="viewSelected" @download="pillDownload" @delete="pillDelete" @clear="clearSelection" />

		<!-- Mobile selection bar replaces the header while in select mode -->
		<div v-if="isMobile && selectMode" class="files-next__selbar">
			<button type="button" class="files-next__iconbtn" :aria-label="t('libresign', 'Cancel selection')"
				@click="exitSelectMode">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiClose" />
				</svg>
			</button>
			<span class="files-next__selbar-count">{{ n('libresign', '{count} selected', '{count} selected',
				selectedIds.size, { count: selectedIds.size }) }}</span>
			<button v-if="selectedIds.size === 1" type="button" class="files-next__iconbtn"
				:aria-label="t('libresign', 'View')" @click="viewSelected">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiEyeOutline" />
				</svg>
			</button>
			<button type="button" class="files-next__iconbtn" :disabled="selectedIds.size === 0"
				:aria-label="t('libresign', 'Download')" @click="bulkDownload">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiDownloadOutline" />
				</svg>
			</button>
			<button type="button" class="files-next__iconbtn files-next__iconbtn--danger"
				:disabled="selectedIds.size === 0" :aria-label="t('libresign', 'Delete')" @click="bulkDelete">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiTrashCanOutline" />
				</svg>
			</button>
		</div>

		<!-- Header: title + document count + controls -->
		<header v-else class="files-next__header">
			<div class="files-next__heading-block">
				<div class="files-next__heading">
					<h1 class="files-next__title">{{ t('libresign', 'Documents') }}</h1>
				</div>
				<p class="files-next__subtitle">
					{{ n('libresign', '{count} document', '{count} documents', rows.length, { count: rows.length }) }}
				</p>
			</div>

			<div class="files-next__header-actions">
				<!-- Native status + modified-date filter dropdowns -->
				<FilesNextFilters />

				<button type="button" class="files-next__ctl" :aria-label="t('libresign', 'Reload')"
					:title="t('libresign', 'Reload')" :disabled="filesStore.loading" @click="refresh">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
						<path :d="mdiReload" />
					</svg>
				</button>
				<button v-if="canRequestSign" type="button" class="files-next__new" @click="goNew">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
						<path :d="mdiPlus" />
					</svg>
					{{ t('libresign', 'New') }}
				</button>
			</div>
		</header>

		<!-- Active filter chips -->
		<FilesNextFilterChips v-if="!isMobile || !selectMode" />

		<!-- Scrollable body -->
		<div ref="scroller" class="files-next__body">

			<!-- Skeleton shimmer during the first load — replaces the spinner -->
			<FilesNextSkeleton v-if="isInitialLoading" :mobile="isMobile" />

			<!-- Real content — fades in once loaded -->
			<template v-else>
				<!-- ===================== DESKTOP: table ===================== -->
				<table v-if="!isMobile" class="files-next__table"
					:class="{ 'files-next__table--has-selection': selectedIds.size > 0 }">
					<thead>
						<tr>
							<th class="col-check" scope="col">
								<input type="checkbox" :checked="allVisibleSelected"
									:indeterminate.prop="someVisibleSelected && !allVisibleSelected"
									:aria-label="t('libresign', 'Select all')" @change="toggleSelectAll">
							</th>
							<template v-for="col in columns" :key="col.id">
								<th scope="col" :class="'col-' + col.id"
									:aria-sort="isSortedBy(col.id) ? (sortDirOf(col.id) === 'asc' ? 'ascending' : 'descending') : 'none'">
									<button type="button" class="files-next__sort" @click="onSort(col.id)">
										<span>{{ col.label }}</span>
										<svg class="icon icon--sm files-next__sort-icon"
											:class="{ 'is-active': isSortedBy(col.id) }" viewBox="0 0 24 24"
											aria-hidden="true">
											<path
												:d="isSortedBy(col.id) ? (sortDirOf(col.id) === 'asc' ? mdiArrowUp : mdiArrowDown) : mdiSwapVertical" />
										</svg>
									</button>
								</th>
								<!-- flexible gutter after the name column keeps meta columns right-aligned -->
								<th v-if="col.id === 'name'" class="col-spacer" scope="col" aria-hidden="true" />
							</template>
							<th class="col-actions" scope="col" aria-hidden="true" />
						</tr>
					</thead>

					<tbody>
						<tr v-for="row in displayRows" :key="row.key" :data-row-id="row.id"
							:class="{ 'is-selected': selectedIds.has(row.id) }"
							@contextmenu="onRowContextMenu($event, row)">
							<td class="col-check">
								<input type="checkbox" :checked="selectedIds.has(row.id)"
									:aria-label="t('libresign', 'Select {name}', { name: row.name })"
									@change="toggleOne(row.id)">
							</td>

							<template v-for="col in columns" :key="col.id">
								<template v-if="col.id === 'name'">
									<td class="col-name">
										<div class="files-next__doc">
											<span class="files-next__filecard">
												<span class="files-next__filecard-ext"
													:style="{ color: badgeColor(row.ext, row.statusCode) }">{{ (row.ext ||
														'file').toUpperCase() }}</span>
												<span class="files-next__filecard-lines"><i /><i /><i /></span>
											</span>
											<button type="button" class="files-next__name-btn" @click="openFile(row)">
												<span class="files-next__name">{{ row.name }}</span>
												<span v-if="row.requester" class="files-next__requester">{{
													row.requester
													}}</span>
											</button>
										</div>
									</td>

									<td class="col-spacer" />
								</template>

								<td v-else-if="col.id === 'status'" class="col-status">
									<span v-if="row.statusLabel && row.statusLabel !== 'none'" class="files-next__pill"
										:class="'files-next__pill--' + statusVariant(row.statusCode)"
										:title="row.statusLabel">
										<span class="files-next__dot" />
										{{ row.statusLabel }}
									</span>
								</td>

								<td v-else-if="col.id === 'signers'" class="col-signers">
									<span v-if="row.signersTotal > 0" class="files-next__signers">
										<span class="files-next__signers-num">{{ row.signersSigned }}</span>
										<span class="files-next__signers-den">/ {{ row.signersTotal }}</span>
									</span>
								</td>

								<td v-else-if="col.id === 'created'" class="col-created">
									<div class="files-next__time">
										<span :title="absoluteTime(row.created)">{{ relativeTime(row.created) }}</span>
										<span v-if="hasUpdateSinceCreated(row.created, row.updated)"
											class="files-next__time-hint" :title="absoluteTime(row.updated)">{{
												t('libresign', 'updated {ago}', { ago: relativeTime(row.updated) }) }}</span>
									</div>
								</td>
							</template>

							<td class="col-actions">
								<button type="button" class="files-next__kebab"
									:aria-label="t('libresign', 'Actions for {name}', { name: row.name })"
									aria-haspopup="menu" @click.stop="onKebab($event, row)">
									<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
										<path :d="mdiDotsHorizontal" />
									</svg>
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- ===================== MOBILE: card rows ===================== -->
				<ul v-else class="files-next__mlist">
					<li v-for="row in displayRows" :key="row.key" :data-row-id="row.id" class="m-row"
						:class="{ 'm-row--selected': selectedIds.has(row.id) }" @click="onMobileRowTap(row)"
						@contextmenu.prevent @pointerdown="onPressStart($event, row)" @pointermove="onPressMove"
						@pointerup="onPressEnd" @pointercancel="onPressEnd" @pointerleave="onPressEnd">
						<span v-if="selectMode" class="m-check" :class="{ 'm-check--on': selectedIds.has(row.id) }"
							aria-hidden="true">
							<svg v-if="selectedIds.has(row.id)" class="icon icon--sm" viewBox="0 0 24 24">
								<path :d="mdiCheck" />
							</svg>
						</span>

						<!-- File-type thumbnail with extension badge (shared with desktop) -->
						<span class="files-next__filecard">
							<span class="files-next__filecard-ext" :style="{ color: badgeColor(row.ext, row.statusCode) }">{{ (row.ext ||
								'file').toUpperCase() }}</span>
							<span class="files-next__filecard-lines"><i /><i /><i /></span>
						</span>

						<span class="m-main">
							<span class="m-name">{{ row.name }}</span>
							<span v-if="row.requester" class="m-req"
								:class="{ 'm-req--email': isEmail(row.requester) }">
								<svg v-if="isEmail(row.requester)" class="icon icon--sm" viewBox="0 0 24 24"
									aria-hidden="true">
									<path :d="mdiEmailOutline" />
								</svg>
								{{ row.requester }}
							</span>
							<span class="m-meta">
								<span v-if="row.statusLabel && row.statusLabel !== 'none'" class="files-next__pill"
									:class="'files-next__pill--' + statusVariant(row.statusCode)">
									<span class="files-next__dot" />
									{{ row.statusLabel }}
								</span>
								<span class="m-time">{{ relativeTime(row.created) }}</span>
							</span>
						</span>

						<span v-if="!selectMode" class="m-actions">
							<button v-if="row.canSign" type="button" class="m-sign" @click.stop="signRow(row)">{{
								t('libresign', 'Sign')
								}}</button>
							<button type="button" class="m-kebab"
								:aria-label="t('libresign', 'Actions for {name}', { name: row.name })"
								aria-haspopup="menu" @click.stop="onKebab($event, row)">
								<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
									<path :d="mdiDotsVertical" />
								</svg>
							</button>
						</span>
					</li>
				</ul>

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
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import {
	mdiArrowDown,
	mdiArrowUp,
	mdiCheck,
	mdiClose,
	mdiDotsHorizontal,
	mdiDotsVertical,
	mdiDownloadOutline,
	mdiEmailOutline,
	mdiEyeOutline,
	mdiFolderOutline,
	mdiPencilOutline,
	mdiPlus,
	mdiReload,
	mdiSignature,
	mdiSwapVertical,
	mdiTextBoxCheck,
	mdiTrashCanOutline,
} from '@mdi/js'

import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import FilesNextSkeleton from './components/FilesNextSkeleton.vue'
import FilesNextSelectionPill from './components/FilesNextSelectionPill.vue'
import FilesNextFilters from './components/FilesNextFilters.vue'
import FilesNextFilterChips from './components/FilesNextFilterChips.vue'
import FilesNextConfirmDialog from './components/FilesNextConfirmDialog.vue'
import FilesNextRowMenu from './components/FilesNextRowMenu.vue'
import { useFilesNextRows, type FilesNextRow } from './useFilesNextRows.ts'
import { useBreakpoint } from './composables/useBreakpoint.ts'
import { useSorting } from './composables/useSorting.ts'
import { useRowSelection } from './composables/useRowSelection.ts'
import { usePagination } from './composables/usePagination.ts'
import { useFiltersStore } from '../../store/filters.js'
import { absoluteTime, badgeColor, hasUpdateSinceCreated, isEmail, relativeTime, statusVariant } from './composables/rowFormatters.ts'
import { useSidebarStore } from '../../store/sidebar.js'
import { useSignStore } from '../../store/sign.js'
import { getSigningRouteUuid } from '../../utils/signRequestUuid.ts'
import { openDocument } from '../../utils/viewer.js'
import { showError, showSuccess } from '../../services/toast'

defineOptions({ name: 'FilesListNext' })

defineProps<{ loading?: boolean }>()
const emit = defineEmits<{ (e: 'update:loading', v: boolean): void }>()

const router = useRouter()
const { filesStore, rows } = useFilesNextRows()
const sidebarStore = useSidebarStore()
const signStore = useSignStore()

const canRequestSign = computed(() => filesStore.canRequestSign)

// Server-side sorting + the visible column set.
const { columns, onSort, isSortedBy, sortDirOf } = useSorting()

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
 * Selection — a Set of ids OUTSIDE the rows, plus mobile long-press select
 * and the pill's single-selection helpers. Action dispatch is injected.
 */
const {
	selectedIds, toggleOne, allVisibleSelected, someVisibleSelected, toggleSelectAll, clearSelection,
	bulkDelete, bulkDownload, selectedRow, canPillDownload, canPillDelete, viewSelected, pillDownload, pillDelete,
	selectMode, exitSelectMode, clearPressTimer, onPressStart, onPressMove, onPressEnd, onMobileRowTap,
} = useRowSelection({
	displayRows,
	filesStore,
	sidebarStore,
	openFile,
	downloadRow,
	deleteRow,
	openBulkDelete: () => openDialog('bulkDelete', null),
})

// Breakpoint: leave mobile select mode when growing back to desktop.
const { isMobile } = useBreakpoint((mobile) => { if (!mobile) { exitSelectMode() } })

/**
 * Action surface: desktop menu / mobile sheet (shared map)
 */
const menu = reactive<{ open: boolean, row: FilesNextRow | null, x: number, y: number }>({
	open: false, row: null, x: 0, y: 0,
})

type MenuItem = {
	id: string
	label: string
	icon: string
	danger?: boolean
	run: () => void | Promise<void>
}

const menuItems = computed<MenuItem[]>(() => {
	const row = menu.row
	if (!row) {
		return []
	}
	const raw = row.raw
	const items: MenuItem[] = []

	// View — opens the right sidebar (document action centre); matches the pill.
	// Consolidates the former "Details" / "Request signature" items (same action).
	items.push({ id: 'view', label: t('libresign', 'View'), icon: mdiEyeOutline, run: () => openDetails(row) })
	if (row.nodeType !== 'envelope' && !filesStore.isOriginalFileDeleted(raw)) {
		items.push({ id: 'open', label: t('libresign', 'Open'), icon: mdiFolderOutline, run: () => openFile(row) })
	}
	items.push({ id: 'download', label: t('libresign', 'Download'), icon: mdiDownloadOutline, run: () => downloadRow(row) })
	items.push({ id: 'rename', label: t('libresign', 'Rename'), icon: mdiPencilOutline, run: () => renameRow(row) })
	if (filesStore.canValidate(raw)) {
		items.push({ id: 'validate', label: t('libresign', 'Validate'), icon: mdiTextBoxCheck, run: () => validateRow(row) })
	}
	if (filesStore.canSign(raw)) {
		items.push({ id: 'sign', label: t('libresign', 'Sign'), icon: mdiSignature, run: () => signRow(row) })
	}
	if (filesStore.canDelete(raw)) {
		items.push({ id: 'delete', label: t('libresign', 'Delete'), icon: mdiTrashCanOutline, danger: true, run: () => deleteRow(row) })
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

function onKeydown(e: KeyboardEvent) {
	// Menu/filter Esc are handled inside their own components; here we only
	// exit mobile select mode.
	if (e.key === 'Escape' && selectMode.value) {
		exitSelectMode()
	}
}

/**
 * Action dispatchers — reuse EXISTING store/router semantics
 */
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
	openDialog('delete', row)
}

function renameRow(row: FilesNextRow) {
	if (!row.uuid) {
		return
	}
	renameValue.value = row.name
	openDialog('rename', row)
}

/**
 * Confirmation / rename dialogs
 */
type DialogKind = 'delete' | 'bulkDelete' | 'rename'
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

/**
 * Soft-collapse a row's height to zero before it's removed from the store,
 * so deletion reads as a gentle collapse rather than a pop-out.
 */
function animateRowRemoval(id: number | string): Promise<void> {
	return new Promise((resolve) => {
		const el = scroller.value?.querySelector(`[data-row-id="${CSS.escape(String(id))}"]`) as HTMLElement | null
		if (!el) {
			resolve()
			return
		}
		const startHeight = el.offsetHeight
		const cells = Array.from(el.querySelectorAll('td')) as HTMLElement[]
		el.style.height = `${startHeight}px`
		el.style.overflow = 'hidden'
		void el.offsetHeight // reflow to lock the starting height
		el.style.transition = 'height 0.28s ease, opacity 0.24s ease, padding 0.28s ease'
		cells.forEach((td) => { td.style.transition = 'padding 0.28s ease' })
		requestAnimationFrame(() => {
			el.style.height = '0'
			el.style.opacity = '0'
			el.style.paddingTop = '0'
			el.style.paddingBottom = '0'
			cells.forEach((td) => { td.style.paddingTop = '0'; td.style.paddingBottom = '0' })
		})
		let settled = false
		const finish = () => { if (!settled) { settled = true; resolve() } }
		el.addEventListener('transitionend', (e) => {
			if ((e as TransitionEvent).propertyName === 'height') { finish() }
		})
		window.setTimeout(finish, 360)
	})
}

async function confirmDialog() {
	// Capture before closeDialog() resets the reactive state.
	const kind = dialog.kind
	const row = dialog.row
	const alsoDeleteFile = deleteFileToo.value
	closeDialog()
	if (kind === 'delete' && row) {
		try {
			await animateRowRemoval(row.id) // collapse the row before it leaves the store
			await filesStore.delete(row.raw, alsoDeleteFile)
			showSuccess(t('libresign', 'Document deleted'))
		} catch (error) {
			showError(t('libresign', 'Could not delete the document'))
		}
	} else if (kind === 'bulkDelete') {
		const targets = displayRows.value.filter((r) => selectedIds.value.has(r.id))
		try {
			await Promise.all(targets.map((r) => animateRowRemoval(r.id)))
			for (const r of targets) {
				await filesStore.delete(r.raw, alsoDeleteFile)
			}
			showSuccess(n('libresign', '{count} document deleted', '{count} documents deleted', targets.length, { count: targets.length }))
		} catch (error) {
			showError(t('libresign', 'Could not delete some documents'))
		}
		clearSelection()
		exitSelectMode()
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

/**
 * Pagination + reload + New + deep-link. `scroller` stays here because the
 * delete-collapse animation queries rows within it.
 */
const scroller = ref<HTMLElement | null>(null)
const sentinel = ref<HTMLElement | null>(null)

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

	.icon {
		width: 20px;
		height: 20px;
		fill: currentColor;
		flex: 0 0 auto;

		&--sm {
			width: 15px;
			height: 15px;
		}
	}

	&--mobile {
		padding: 8px 12px 0;

		// Stack the header on mobile: title/count on its own row, controls below
		// (between the header and the list) so they never crowd the title.
		.files-next__header {
			flex-direction: column;
			align-items: stretch;
			gap: 12px;
		}

		// One compact controls row: reload at the far left, then filters, then New.
		.files-next__header-actions {
			flex-wrap: nowrap;
			align-items: center;
			gap: 6px;
			overflow-x: auto;
			scrollbar-width: none;

			&::-webkit-scrollbar { display: none; }
		}
		.files-next__ctl {
			order: -1; // reload first
			flex: 0 0 auto;
			width: 34px;
			height: 34px;
		}
		.files-next__new {
			flex: 0 0 auto;
			height: 34px;
			padding: 0 12px;
			font-size: 12.5px;
			gap: 4px;
		}

		// Enlarge the shared file-card on mobile (matches the screenshots)
		.files-next__filecard {
			flex-basis: 48px;
			width: 48px;
			height: 60px;
			padding: 7px 6px;
			border-radius: 8px;
			margin-inline-end: 0;

			.files-next__filecard-ext {
				font-size: 9px;
			}

			.files-next__filecard-lines {
				gap: 4px;
				margin-top: 8px;

				i {
					height: 3px;
				}

				i:nth-child(2) {
					width: 80%;
				}

				i:nth-child(3) {
					width: 90%;
				}
			}
		}
	}

	// Header
	&__header {
		margin-top: 30px;
		flex: 0 0 auto;
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16px;
		padding: 16px 4px 12px;
	}

	&__heading-block { min-width: 0; }

	&__header-actions {
		display: flex;
		align-items: center;
		gap: 8px;
		flex: 0 0 auto;
	}

	&__ctl {
		order: -1; // reload sits first (far left of the controls)
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 34px;
		height: 34px;
		border: none;
		border-radius: 50%;
		background: transparent;
		color: var(--color-text-maxcontrast);
		cursor: pointer;

		.icon { width: 18px; height: 18px; fill: currentColor; }

		&:hover { background: var(--color-background-dark); color: var(--color-main-text); }
		&:disabled { opacity: 0.5; cursor: default; }
	}

	&__new {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		height: 34px;
		padding: 0 14px;
		border: none;
		border-radius: 17px;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text, #fff);
		font-size: 13px;
		font-weight: 500;
		cursor: pointer;

		.icon { width: 18px; height: 18px; fill: currentColor; }

		&:hover { background: var(--color-primary-element-hover, var(--color-primary-element)); }
	}

	&__heading {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	&__title {
		margin: 0;
		font-size: 28px;
		font-weight: 700;
		line-height: 1.1;
	}

	&__badge {
		font-size: 11px;
		font-weight: 600;
		padding: 2px 8px;
		border-radius: 10px;
		background: var(--color-warning, #e9a900);
		color: var(--color-primary-element-text, #fff);
		text-transform: uppercase;
		letter-spacing: 0.04em;
	}

	&__subtitle {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}

	// Mobile selection bar
	&__selbar {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 14px 8px 12px;
		flex: 0 0 auto;

		&-count {
			font-size: 17px;
			font-weight: 600;
			margin-inline-end: auto;
		}
	}

	&__iconbtn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 44px;
		height: 44px;
		border: none;
		border-radius: 50%;
		background: transparent;
		color: var(--color-main-text);
		cursor: pointer;

		&:hover {
			background: var(--color-background-dark);
		}

		&:disabled {
			opacity: 0.4;
			cursor: default;
		}

		&--danger {
			color: #d32f2f;
		}
	}

	// Floating selection pill — dark, pinned to the top of the list
	// Body
	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
	}

	// Desktop table
	&__table {
		width: 100%;
		border-collapse: collapse;
		table-layout: fixed;
		animation: files-next-fadein 0.35s ease both; // real rows fade in after skeleton

		thead th {
			position: sticky;
			top: 0;
			z-index: 1;
			background: var(--color-main-background);
			padding: 10px 12px;
			text-align: start;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: var(--color-text-maxcontrast);
			border-bottom: 1px solid var(--color-border);
			white-space: nowrap;
		}

		tbody td {
			padding: 12px;
			border-bottom: 1px solid var(--color-border);
			vertical-align: middle;
		}

		tbody tr {
			&:hover {
				background: var(--color-background-hover);
			}

			&.is-selected {
				background: var(--color-primary-element-light, var(--color-background-dark));
			}
		}

		// Checkboxes are hidden by default and revealed on row hover, on keyboard
		// focus, and whenever a selection is active (so checked rows stay managed).
		.col-check input[type="checkbox"] {
			opacity: 0;
		}

		thead tr:hover .col-check input[type="checkbox"],
		tbody tr:hover .col-check input[type="checkbox"],
		tbody tr.is-selected .col-check input[type="checkbox"],
		.col-check input[type="checkbox"]:focus-visible {
			opacity: 1;
		}

		&--has-selection .col-check input[type="checkbox"] {
			opacity: 1;
		}
	}

	.col-check {
		width: 44px;
		text-align: center;
		padding-inline: 0;
	}

	.col-name {
		width: auto;
	}

	// Flexible gutter: name + spacer are both auto, so they split the leftover
	// space evenly — the name column stops sprawling and the meta columns stay
	// right-aligned.
	.col-spacer {
		width: auto;
		padding: 0;
		border-bottom: 1px solid var(--color-border);
	}

	.col-status {
		width: 180px;
	}

	.col-signers {
		width: 120px;
	}

	.col-created {
		width: 140px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__time {
		display: flex;
		flex-direction: column;
		gap: 1px;
		min-width: 0;
	}

	&__time-hint {
		font-size: 11px;
		color: var(--color-text-maxcontrast);
		opacity: 0.75;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.col-actions {
		width: 56px;
		text-align: center;
		padding-inline: 0;
	}

	// Custom checkbox — removes the native border/focus artifact, green when active
	input[type="checkbox"] {
		appearance: none;
		-webkit-appearance: none;
		box-sizing: border-box;
		flex: 0 0 auto;
		// Fixed square — override Nextcloud's global min-height (clickable-area)
		// that otherwise stretches the box into a tall rectangle.
		width: 15px;
		height: 15px;
		min-width: 15px;
		min-height: 15px;
		max-width: 15px;
		max-height: 15px;
		margin: 0;
		padding: 0;
		vertical-align: middle;
		border: 1px solid #CDD0D4;
		border-radius: 5px;
		background: var(--color-main-background);
		cursor: pointer;
		display: inline-grid;
		place-content: center;
		transition: background-color 0.12s ease, border-color 0.12s ease, opacity 0.12s ease;

		&::before {
			content: "";
			width: 11px;
			height: 11px;
			transform: scale(0);
			background-color: #fff;
			clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0, 43% 62%);
			transition: transform 0.1s ease;
		}

		&:checked,
		&:indeterminate {
			background-color: #04d56d;
			border-color: #04d56d;
		}

		&:checked::before {
			transform: scale(1);
		}

		&:indeterminate::before {
			transform: scale(1);
			width: 10px;
			height: 2px;
			border-radius: 1px;
			clip-path: none;
		}

		&:focus {
			outline: none;
		}

		&:focus-visible {
			outline: 2px solid #04d56d;
			outline-offset: 2px;
		}
	}

	&__sort {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		border: none;
		background: transparent;
		color: inherit;
		font: inherit;
		text-transform: inherit;
		letter-spacing: inherit;
		cursor: pointer;
		padding: 0;

		&:hover {
			color: var(--color-main-text);
		}
	}

	&__sort-icon {
		opacity: 0.35;

		&.is-active {
			opacity: 0.9;
		}
	}

	&__doc {
		display: flex;
		align-items: center;
		min-width: 0;
	}

	// File-type card thumbnail — shared by desktop + mobile (mobile enlarges below)
	&__filecard {
		position: relative;
		display: flex;
		flex-direction: column;
		flex: 0 0 40px;
		width: 40px;
		height: 50px;
		padding: 6px 5px;
		box-sizing: border-box;
		border: 1px solid var(--color-border);
		border-radius: 7px;
		background: var(--color-main-background);
		margin-inline-end: 12px;

		&-ext {
			font-size: 8px;
			font-weight: 800;
			line-height: 1;
			letter-spacing: 0.02em;
		}

		&-lines {
			display: flex;
			flex-direction: column;
			gap: 3px;
			margin-top: 6px;

			i {
				height: 2.5px;
				border-radius: 2px;
				background: var(--color-border);
			}

			i:nth-child(1) {
				width: 100%;
			}

			i:nth-child(2) {
				width: 72%;
			}

			i:nth-child(3) {
				width: 88%;
			}
		}
	}

	&__name-btn {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		min-width: 0;
		border: none;
		background: transparent;
		text-align: start;
		cursor: pointer;
		padding: 0;
		color: inherit;
	}

	&__name {
		max-width: 100%;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: 15px;
		font-weight: 500;
		color: var(--color-main-text);
	}

	&__requester {
		max-width: 100%;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: 11.5px;
		font-weight: 400;
		color: #9aa0a6;
		margin-top: 1px;
	}

	// Status pill (shared desktop + mobile)
	&__pill {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		max-width: 100%;
		padding: 4px 11px;
		border-radius: 12px;
		font-size: 13px;
		font-weight: 500;
		line-height: 1.3;
		white-space: nowrap;
	}

	&__dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		flex: 0 0 auto;
		background: currentColor;
	}

	&__pill--draft {
		background: #f0f0f0;
		color: #616161;
	}

	&__pill--ready {
		background: #fff6e0;
		color: #9a7500;
	}

	&__pill--partial {
		background: #ecedf9;
		color: #3f51b5;
	}

	&__pill--signed {
		background: #e6f4ea;
		color: #1e7d32;
	}

	&__pill--neutral {
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}

	&__signers {
		font-size: 14px;
		font-weight: 500;
		color: var(--color-main-text);
	}

	&__signers-den {
		color: var(--color-text-maxcontrast);
		font-weight: 400;
		margin-inline-start: 3px;
	}

	&__kebab {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 34px;
		height: 34px;
		border: none;
		border-radius: 50%;
		background: transparent;
		color: var(--color-text-maxcontrast);
		cursor: pointer;

		&:hover {
			background: var(--color-background-dark);
			color: var(--color-main-text);
		}
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

	// Mobile card list
	&__mlist {
		list-style: none;
		margin: 0;
		padding: 0;
		animation: files-next-fadein 0.35s ease both; // real rows fade in after skeleton
	}
}

.m-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 4px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	user-select: none;
	-webkit-tap-highlight-color: transparent;

	&--selected {
		background: var(--color-primary-element-light, var(--color-background-dark));
	}
}

.m-check {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 24px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid var(--color-border-maxcontrast, #999);
	color: var(--color-primary-element-text, #fff);

	&--on {
		background: #04d56d;
		border-color: #04d56d;
	}
}

.m-main {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
	flex: 1 1 auto;
}

.m-name {
	font-size: 16px;
	font-weight: 500;
	color: var(--color-main-text);
	line-height: 1.25;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	// Break long unbroken filenames (e.g. underscore_pdf_names) so they fill
	// the 2-line clamp instead of truncating on a single line.
	overflow-wrap: anywhere;
	word-break: break-word;
}

.m-req {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	max-width: 100%;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;

	.icon {
		opacity: 0.7;
	}
}

.m-meta {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
}

.m-time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.m-actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.m-sign {
	height: 34px;
	padding: 0 14px;
	border: none;
	border-radius: 17px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}

.m-kebab {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	.icon {
		width: 22px;
		height: 22px;
		fill: currentColor;
	}

	&:hover {
		background: var(--color-background-dark);
	}
}



@keyframes files-next-fadein {
	from {
		opacity: 0;
		transform: translateY(4px);
	}

	to {
		opacity: 1;
		transform: translateY(0);
	}
}
</style>
