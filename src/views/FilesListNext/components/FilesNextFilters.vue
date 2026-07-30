<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Native status + modified-date filter dropdowns. Self-contained: owns the
  - filters composable (which commits to the existing filters store) and its own
  - dismiss listeners. Panels are teleported to <body> so no ancestor overflow
  - or stacking context can clip them.
-->
<template>
	<div class="fn-filters">
		<button type="button" class="fn-filter__btn"
			:class="{ 'fn-filter__btn--active': statusSelected.length > 0, 'fn-filter__btn--open': openFilter === 'status' }"
			aria-haspopup="menu" :aria-expanded="openFilter === 'status'" @click.stop="toggleFilter('status', $event)">
			<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiListStatus" /></svg>
			<span>{{ t('libresign', 'Status') }}</span>
			<span v-if="statusSelected.length > 0" class="fn-filter__count">{{ statusSelected.length }}</span>
			<svg class="icon icon--sm fn-filter__chev" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiChevronDown" /></svg>
		</button>

		<button type="button" class="fn-filter__btn"
			:class="{ 'fn-filter__btn--active': !!modifiedSelected, 'fn-filter__btn--open': openFilter === 'modified' }"
			aria-haspopup="menu" :aria-expanded="openFilter === 'modified'" @click.stop="toggleFilter('modified', $event)">
			<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCalendarRange" /></svg>
			<span>{{ t('libresign', 'Modified') }}</span>
			<svg class="icon icon--sm fn-filter__chev" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiChevronDown" /></svg>
		</button>

		<Teleport to="body">
			<div v-if="openFilter" class="fn-portal" :style="{ top: filterAnchor.y + 'px', left: filterAnchor.x + 'px' }">
				<div v-if="openFilter === 'status'" class="fn-filter__panel" role="menu">
					<button v-for="opt in statusOptions" :key="opt.id" type="button" class="fn-filter__item"
						role="menuitemcheckbox" :aria-checked="statusSelected.includes(opt.id)" @click="toggleStatus(opt.id)">
						<span class="fn-filter__check" :class="{ 'fn-filter__check--on': statusSelected.includes(opt.id) }">
							<svg v-if="statusSelected.includes(opt.id)" class="icon icon--sm" viewBox="0 0 24 24"><path :d="mdiCheck" /></svg>
						</span>
						<span class="fn-dot" :class="'fn-dot--' + opt.variant" />
						<span>{{ opt.label }}</span>
					</button>
				</div>
				<div v-else-if="openFilter === 'modified'" class="fn-filter__panel" role="menu">
					<button v-for="p in timePresets" :key="p.id" type="button" class="fn-filter__item"
						role="menuitemradio" :aria-checked="modifiedSelected === p.id" @click="selectModified(p.id)">
						<span class="fn-filter__radio" :class="{ 'fn-filter__radio--on': modifiedSelected === p.id }" />
						<span>{{ p.label }}</span>
					</button>
				</div>
			</div>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'

import { mdiCalendarRange, mdiCheck, mdiChevronDown, mdiListStatus } from '@mdi/js'
import { t } from '@nextcloud/l10n'

import { useFilesNextFilters } from '../composables/useFilesNextFilters.ts'

defineOptions({ name: 'FilesNextFilters' })

const {
	openFilter, filterAnchor, toggleFilter, closeFilter,
	statusOptions, timePresets, statusSelected, modifiedSelected, toggleStatus, selectModified,
} = useFilesNextFilters()

// Dismiss on outside-click (the teleported panel is excluded), Esc, any scroll
// (capture catches the list scroll too), and resize.
function onDocMouseDown(e: MouseEvent) {
	if (!openFilter.value) {
		return
	}
	const target = e.target as HTMLElement
	if (!target?.closest?.('.fn-filters') && !target?.closest?.('.fn-portal')) {
		closeFilter()
	}
}
function onKeydown(e: KeyboardEvent) {
	if (e.key === 'Escape' && openFilter.value) {
		closeFilter()
	}
}
function onScrollCapture() {
	if (openFilter.value) {
		closeFilter()
	}
}

onMounted(() => {
	document.addEventListener('mousedown', onDocMouseDown, true)
	document.addEventListener('keydown', onKeydown)
	document.addEventListener('scroll', onScrollCapture, true)
	window.addEventListener('resize', closeFilter)
})
onBeforeUnmount(() => {
	document.removeEventListener('mousedown', onDocMouseDown, true)
	document.removeEventListener('keydown', onKeydown)
	document.removeEventListener('scroll', onScrollCapture, true)
	window.removeEventListener('resize', closeFilter)
})
</script>

<style scoped lang="scss">
.fn-filters {
	display: flex;
	align-items: center;
	gap: 8px;

	.icon--sm { width: 16px; height: 16px; }
}
.fn-filter__btn {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	height: 34px;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 17px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	font-weight: 400;
	cursor: pointer;

	.icon { width: 16px; height: 16px; fill: currentColor; color: var(--color-text-maxcontrast); }

	&:hover { background: var(--color-background-hover); }
	&--open { border-color: var(--color-primary-element); }
	&--active {
		border-color: var(--color-primary-element);
		color: var(--color-primary-element);
		.icon { color: var(--color-primary-element); }
	}
}
.fn-filter__count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 5px;
	border-radius: 9px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font-size: 11px;
	font-weight: 700;
}
</style>

<!--
  Teleported panels live on <body>: unscoped, reset with `all: unset` so the
  app's global <button> styling can't bleed in.
-->
<style lang="scss">
.fn-portal {
	position: fixed;
	z-index: 100000;
}
.fn-portal .fn-filter__panel {
	min-width: 224px;
	padding: 6px;
	border-radius: 14px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ededed);
	box-shadow: 0 10px 34px rgba(0, 0, 0, 0.18);
	animation: fn-filter-fadein 0.14s ease both;
}
.fn-portal button.fn-filter__item {
	all: unset;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 9px 10px;
	border-radius: 8px;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 400;
	cursor: pointer;

	&:hover, &:focus-visible { background: var(--color-background-hover, #f5f5f5); }
}
.fn-portal .fn-filter__check {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 18px;
	height: 18px;
	border: 2px solid var(--color-border-maxcontrast, #b0b0b0);
	border-radius: 5px;

	.icon { width: 14px; height: 14px; fill: #fff; }

	&--on { background: #04d56d; border-color: #04d56d; }
}
.fn-portal .fn-filter__radio {
	position: relative;
	flex: 0 0 auto;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-border-maxcontrast, #b0b0b0);
	border-radius: 50%;

	&--on {
		border-color: #04d56d;
		&::after { content: ''; position: absolute; inset: 3px; border-radius: 50%; background: #04d56d; }
	}
}
.fn-portal .fn-dot {
	flex: 0 0 auto;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	&--draft { background: #9e9e9e; }
	&--ready { background: #d4a843; }
	&--partial { background: #3f51b5; }
	&--signed { background: #2e7d32; }
	&--neutral { background: var(--color-text-maxcontrast); }
}

@keyframes fn-filter-fadein {
	from { opacity: 0; transform: translateY(-6px); }
	to { opacity: 1; transform: translateY(0); }
}
</style>
