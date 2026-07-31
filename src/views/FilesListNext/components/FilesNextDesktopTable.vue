<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Desktop presentation of the Files list: a semantic <table> with sortable
  - headers (server-side sorting via the sorting store), selection checkboxes
  - and the per-row kebab. Presentation only — rows and selection arrive as
  - props and every interaction is emitted upward.
-->
<template>
	<table class="files-next__table" :class="{ 'files-next__table--has-selection': selectedIds.size > 0 }">
		<thead>
			<tr>
				<th class="col-check" scope="col">
					<input type="checkbox" :checked="allVisibleSelected"
						:indeterminate.prop="someVisibleSelected && !allVisibleSelected"
						:aria-label="t('libresign', 'Select all')" @change="$emit('select-all')">
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
				@contextmenu="$emit('context-menu', $event, row)">
				<td class="col-check">
					<input type="checkbox" :checked="selectedIds.has(row.id)"
						:aria-label="t('libresign', 'Select {name}', { name: row.name })"
						@change="$emit('toggle-one', row.id)">
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
								<button type="button" class="files-next__name-btn" @click="$emit('open', row)">
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
						aria-haspopup="menu" @click.stop="$emit('kebab', $event, row)">
						<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
							<path :d="mdiDotsHorizontal" />
						</svg>
					</button>
				</td>
			</tr>
		</tbody>
	</table>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { mdiArrowDown, mdiArrowUp, mdiDotsHorizontal, mdiSwapVertical } from '@mdi/js'

import { t } from '@nextcloud/l10n'

import type { FilesNextRow } from '../useFilesNextRows.ts'
import { useSorting, type Column } from '../composables/useSorting.ts'
import { absoluteTime, badgeColor, hasUpdateSinceCreated, relativeTime, statusVariant } from '../composables/rowFormatters.ts'

defineOptions({ name: 'FilesNextDesktopTable' })

const props = defineProps<{
	columns: Column[]
	displayRows: FilesNextRow[]
	selectedIds: Set<number | string>
	isMobile: boolean
}>()

defineEmits<{
	(e: 'toggle-one', id: number | string): void
	(e: 'select-all'): void
	(e: 'context-menu', event: MouseEvent, row: FilesNextRow): void
	(e: 'kebab', event: MouseEvent, row: FilesNextRow): void
	(e: 'open', row: FilesNextRow): void
}>()

// Server-side sorting: clicking a header drives the filesSorting store.
const { onSort, isSortedBy, sortDirOf } = useSorting()

const allVisibleSelected = computed(() =>
	props.displayRows.length > 0 && props.displayRows.every((r) => props.selectedIds.has(r.id)),
)
const someVisibleSelected = computed(() => props.displayRows.some((r) => props.selectedIds.has(r.id)))
</script>

<style scoped lang="scss">
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

// Desktop table
.files-next__table {
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

.files-next__time {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
}

.files-next__time-hint {
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

.files-next__sort {
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

.files-next__sort-icon {
	opacity: 0.35;

	&.is-active {
		opacity: 0.9;
	}
}

.files-next__doc {
	display: flex;
	align-items: center;
	min-width: 0;
}

// File-type card thumbnail — desktop size (the mobile list enlarges its own)
.files-next__filecard {
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

.files-next__name-btn {
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

.files-next__name {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 15px;
	font-weight: 500;
	color: var(--color-main-text);
}

.files-next__requester {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 11.5px;
	font-weight: 400;
	color: #9aa0a6;
	margin-top: 1px;
}

// Status pill
.files-next__pill {
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

.files-next__dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex: 0 0 auto;
	background: currentColor;
}

.files-next__pill--draft {
	background: #f0f0f0;
	color: #616161;
}

.files-next__pill--ready {
	background: #fff6e0;
	color: #9a7500;
}

.files-next__pill--partial {
	background: #ecedf9;
	color: #3f51b5;
}

.files-next__pill--signed {
	background: #e6f4ea;
	color: #1e7d32;
}

.files-next__pill--neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.files-next__signers {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
}

.files-next__signers-den {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
	margin-inline-start: 3px;
}

.files-next__kebab {
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
