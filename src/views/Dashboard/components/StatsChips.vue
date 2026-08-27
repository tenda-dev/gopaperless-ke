<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="doc-stats" aria-label="Documents by status">
		<div class="doc-stats__head">
			<h2 class="doc-stats__title">Documents</h2>
			<button type="button" class="doc-stats__all"
				@click="openDocuments(DASHBOARD_STATUS.all)"
				@keydown="onActivateKey($event, DASHBOARD_STATUS.all)">
				View all →
			</button>
		</div>

		<div class="doc-stats__scroller">
			<div class="doc-stats__row" role="list">
				<button v-for="c in chips" :key="c.key" type="button" class="doc-chip" role="listitem"
					:aria-label="`${c.label} documents, ${c.count}`"
					@click="openDocuments(c.statuses)" @keydown="onActivateKey($event, c.statuses)">
					<span class="doc-chip__dot" :class="'doc-chip__dot--' + c.key" />
					<span class="doc-chip__lab">{{ c.label }}</span>
					<span class="doc-chip__ct">{{ c.count }}</span>
				</button>
			</div>
			<div class="doc-stats__fade" aria-hidden="true" />
		</div>
	</section>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { useDashboardStatusNav } from '../composables/useDashboardStatusNav'

const props = defineProps<{
	stats: {
		totalDocuments: number
		pendingDocuments: number
		completedDocuments: number
		draftDocuments: number
	}
}>()

const { openDocuments, onActivateKey, DASHBOARD_STATUS } = useDashboardStatusNav()

const chips = computed(() => [
	{ key: 'all', label: 'All', count: props.stats.totalDocuments, statuses: DASHBOARD_STATUS.all },
	{ key: 'pending', label: 'Pending', count: props.stats.pendingDocuments, statuses: DASHBOARD_STATUS.pending },
	{ key: 'completed', label: 'Completed', count: props.stats.completedDocuments, statuses: DASHBOARD_STATUS.completed },
	{ key: 'drafts', label: 'Drafts', count: props.stats.draftDocuments, statuses: DASHBOARD_STATUS.drafts },
])
</script>

<style scoped lang="scss">
.doc-stats__head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin-bottom: 10px;
}

.doc-stats__title {
	margin: 0;
	font-size: 15px;
	font-weight: 650;
	letter-spacing: -0.2px;
	color: var(--color-main-text);
}

.doc-stats__all {
	appearance: none;
	background: none;
	border: 0;
	font: inherit;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-primary-element);
	cursor: pointer;
	padding: 4px;
}

.doc-stats__scroller {
	position: relative;
}

.doc-stats__row {
	display: flex;
	gap: 8px;
	overflow-x: auto;
	padding-bottom: 2px;
	scrollbar-width: none;
	-webkit-overflow-scrolling: touch;
	scroll-snap-type: x proximity;
}

.doc-stats__row::-webkit-scrollbar {
	display: none;
}

.doc-stats__fade {
	position: absolute;
	inset: 0 0 0 auto;
	width: 34px;
	pointer-events: none;
	background: linear-gradient(90deg, transparent, var(--color-main-background));
}

.doc-chip {
	appearance: none;
	-webkit-appearance: none;
	font: inherit;
	cursor: pointer;
	flex: 0 0 auto;
	scroll-snap-align: start;

	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-height: 44px;
	padding: 0 14px;

	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.doc-chip:active {
	transform: translateY(0.5px);
}

.doc-chip:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.doc-chip__dot {
	width: 7px;
	height: 7px;
	flex: 0 0 auto;
	border-radius: 50%;
}

.doc-chip__dot--all,
.doc-chip__dot--drafts {
	background: var(--color-text-maxcontrast);
}

.doc-chip__dot--pending {
	background: #d98a16;
}

.doc-chip__dot--completed {
	background: var(--color-success, #04d56d);
}

.doc-chip__lab {
	font-size: 13px;
	font-weight: 600;
}

.doc-chip__ct {
	font-size: 12.5px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
