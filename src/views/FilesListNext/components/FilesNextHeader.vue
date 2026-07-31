<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Header for the Files list: title + document count + filters + reload/New
  - controls. On mobile, while select mode is active, the header is replaced
  - by the selection bar (count + view/download/delete + cancel). Presentation
  - only: state arrives as props and every action is emitted upward.
-->
<template>
	<!-- Mobile selection bar replaces the header while in select mode -->
	<div v-if="isMobile && selectMode" class="files-next__selbar">
		<button type="button" class="files-next__iconbtn" :aria-label="t('libresign', 'Cancel selection')"
			@click="$emit('exit-select')">
			<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
				<path :d="mdiClose" />
			</svg>
		</button>
		<span class="files-next__selbar-count">{{ n('libresign', '{count} selected', '{count} selected',
			selectedCount, { count: selectedCount }) }}</span>
		<button v-if="selectedCount === 1" type="button" class="files-next__iconbtn"
			:aria-label="t('libresign', 'View')" @click="$emit('view')">
			<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
				<path :d="mdiEyeOutline" />
			</svg>
		</button>
		<button type="button" class="files-next__iconbtn" :disabled="selectedCount === 0"
			:aria-label="t('libresign', 'Download')" @click="$emit('download')">
			<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
				<path :d="mdiDownloadOutline" />
			</svg>
		</button>
		<button type="button" class="files-next__iconbtn files-next__iconbtn--danger"
			:disabled="selectedCount === 0" :aria-label="t('libresign', 'Delete')" @click="$emit('delete')">
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
				{{ n('libresign', '{count} document', '{count} documents', rowCount, { count: rowCount }) }}
			</p>
		</div>

		<div class="files-next__header-actions">
			<!-- Native status + modified-date filter dropdowns -->
			<FilesNextFilters />

			<button type="button" class="files-next__ctl" :aria-label="t('libresign', 'Reload')"
				:title="t('libresign', 'Reload')" :disabled="loading" @click="$emit('refresh')">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiReload" />
				</svg>
			</button>
			<button v-if="canRequestSign" type="button" class="files-next__new" @click="$emit('new')">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
					<path :d="mdiPlus" />
				</svg>
				{{ t('libresign', 'New') }}
			</button>
		</div>
	</header>
</template>

<script setup lang="ts">
import {
	mdiClose,
	mdiDownloadOutline,
	mdiEyeOutline,
	mdiPlus,
	mdiReload,
	mdiTrashCanOutline,
} from '@mdi/js'

import { n, t } from '@nextcloud/l10n'

import FilesNextFilters from './FilesNextFilters.vue'

defineOptions({ name: 'FilesNextHeader' })

defineProps<{
	isMobile: boolean
	selectMode: boolean
	selectedCount: number
	rowCount: number
	canRequestSign: boolean
	loading: boolean
}>()

defineEmits<{
	(e: 'refresh'): void
	(e: 'new'): void
	(e: 'exit-select'): void
	(e: 'view'): void
	(e: 'download'): void
	(e: 'delete'): void
	(e: 'clear'): void
}>()
</script>

<style scoped lang="scss">
.icon {
	width: 20px;
	height: 20px;
	fill: currentColor;
	flex: 0 0 auto;
}

// Header
.files-next__header {
	margin-top: 30px;
	flex: 0 0 auto;
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	padding: 16px 4px 12px;
}

.files-next__heading-block { min-width: 0; }

.files-next__header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 0 0 auto;
}

.files-next__ctl {
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

.files-next__new {
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

.files-next__heading {
	display: flex;
	align-items: center;
	gap: 10px;
}

.files-next__title {
	margin: 0;
	font-size: 28px;
	font-weight: 700;
	line-height: 1.1;
}

.files-next__badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-warning, #e9a900);
	color: var(--color-primary-element-text, #fff);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.files-next__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

// Mobile selection bar
.files-next__selbar {
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

.files-next__iconbtn {
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

// Mobile layout — the parent view adds .files-next--mobile on its root.
.files-next--mobile {
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
}
</style>
