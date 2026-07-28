<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Row action surface: the desktop anchored context menu and the mobile bottom
  - sheet. Both are teleported to <body> so no ancestor can clip them. It renders
  - the action items it's given and emits `select`; the parent owns the actual
  - dispatch. Dismiss (outside-click / Esc / scroll / resize / scrim / swipe) is
  - handled here and surfaced as `close`.
-->
<template>
	<!-- Desktop: anchored menu -->
	<Teleport to="body">
		<div v-if="open && row && !mobile" ref="menuEl" class="files-next-menu" role="menu"
			:style="{ top: pos.y + 'px', left: pos.x + 'px' }">
			<div class="files-next-menu__title">{{ row.name }}</div>
			<template v-for="item in items" :key="item.id">
				<div v-if="item.danger" class="files-next-menu__sep" />
				<button type="button" class="files-next-menu__item"
					:class="{ 'files-next-menu__item--danger': item.danger }" role="menuitem"
					@click="$emit('select', item)">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="item.icon" /></svg>
					<span>{{ item.label }}</span>
				</button>
			</template>
		</div>
	</Teleport>

	<!-- Mobile: bottom sheet -->
	<Teleport to="body">
		<div v-if="open && row && mobile" class="files-next-sheet">
			<div class="files-next-sheet__scrim" @click="$emit('close')" />
			<div class="files-next-sheet__panel" role="menu" :style="{ transform: `translateY(${sheetDrag}px)` }"
				@touchstart="onSheetTouchStart" @touchmove="onSheetTouchMove" @touchend="onSheetTouchEnd">
				<div class="files-next-sheet__handle" />
				<div class="files-next-sheet__head">
					<div class="files-next-sheet__name">{{ row.name }}</div>
					<div class="files-next-sheet__sub">
						<span v-if="row.statusLabel && row.statusLabel !== 'none'"
							class="fn-sheet-pill" :class="'fn-sheet-pill--' + statusVariant(row.statusCode)">
							<span class="fn-sheet-dot" />
							{{ row.statusLabel }}
						</span>
						<span v-if="row.requester" class="files-next-sheet__req">{{ row.requester }}</span>
					</div>
				</div>
				<div class="files-next-sheet__items">
					<template v-for="item in items" :key="item.id">
						<div v-if="item.danger" class="files-next-sheet__sep" />
						<button type="button" class="files-next-sheet__item"
							:class="{ 'files-next-sheet__item--danger': item.danger }" role="menuitem"
							@click="$emit('select', item)">
							<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="item.icon" /></svg>
							<span>{{ item.label }}</span>
						</button>
					</template>
				</div>
				<button type="button" class="files-next-sheet__cancel" @click="$emit('close')">{{ t('libresign', 'Cancel') }}</button>
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import { t } from '@nextcloud/l10n'

import type { FilesNextRow } from '../useFilesNextRows.ts'
import { statusVariant } from '../composables/rowFormatters.ts'

export type MenuItem = {
	id: string
	label: string
	icon: string
	danger?: boolean
	run: () => void | Promise<void>
}

defineOptions({ name: 'FilesNextRowMenu' })

const props = defineProps<{
	open: boolean
	row: FilesNextRow | null
	items: MenuItem[]
	x: number
	y: number
	mobile: boolean
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'select', item: MenuItem): void
}>()

const menuEl = ref<HTMLElement | null>(null)
const pos = reactive({ x: 0, y: 0 })
const sheetDrag = ref(0)

// Position (desktop) / reset drag (mobile) whenever the menu opens or re-anchors.
watch(() => [props.open, props.x, props.y, props.mobile], () => {
	if (!props.open) {
		return
	}
	if (props.mobile) {
		sheetDrag.value = 0
		return
	}
	pos.x = props.x
	pos.y = props.y
	nextTick(() => {
		const el = menuEl.value
		const w = el?.offsetWidth ?? 224
		const h = el?.offsetHeight ?? 260
		pos.x = Math.min(props.x, window.innerWidth - w - 8)
		pos.y = Math.min(props.y, window.innerHeight - h - 8)
	})
}, { immediate: true })

/* Bottom-sheet swipe-to-dismiss */
let sheetTouchStartY = 0
function onSheetTouchStart(e: TouchEvent) {
	sheetTouchStartY = e.touches[0]?.clientY ?? 0
}
function onSheetTouchMove(e: TouchEvent) {
	sheetDrag.value = Math.max(0, (e.touches[0]?.clientY ?? 0) - sheetTouchStartY)
}
function onSheetTouchEnd() {
	if (sheetDrag.value > 80) {
		emit('close')
	} else {
		sheetDrag.value = 0
	}
}

/* Dismiss (desktop): outside-click, Esc, scroll, resize */
function onDocMouseDown(e: MouseEvent) {
	if (props.open && !props.mobile && menuEl.value && !menuEl.value.contains(e.target as Node)) {
		emit('close')
	}
}
function onKeydown(e: KeyboardEvent) {
	if (e.key === 'Escape' && props.open) {
		emit('close')
	}
}
function onScrollCapture() {
	if (props.open && !props.mobile) {
		emit('close')
	}
}
function onResize() {
	if (props.open) {
		emit('close')
	}
}

onMounted(() => {
	document.addEventListener('mousedown', onDocMouseDown, true)
	document.addEventListener('keydown', onKeydown)
	document.addEventListener('scroll', onScrollCapture, true)
	window.addEventListener('resize', onResize)
})
onBeforeUnmount(() => {
	document.removeEventListener('mousedown', onDocMouseDown, true)
	document.removeEventListener('keydown', onKeydown)
	document.removeEventListener('scroll', onScrollCapture, true)
	window.removeEventListener('resize', onResize)
})
</script>

<!-- Teleported to <body> → unscoped; items reset with `all: unset`. -->
<style lang="scss">
.files-next-menu {
	position: fixed;
	z-index: 100000;
	min-width: 224px;
	padding: 6px;
	border-radius: 14px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ededed);
	box-shadow: 0 10px 34px rgba(0, 0, 0, 0.18);
}
.files-next-menu__title {
	padding: 8px 12px 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 280px;
}
.files-next-menu__sep {
	height: 1px;
	margin: 6px 6px;
	background: var(--color-border);
}
.files-next-menu button.files-next-menu__item {
	all: unset;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 10px 12px;
	border-radius: 10px;
	color: var(--color-main-text);
	font-size: 15px;
	line-height: 1.3;
	cursor: pointer;

	.icon { width: 20px; height: 20px; fill: currentColor; color: var(--color-text-maxcontrast); flex: 0 0 auto; }

	&:hover, &:focus-visible { background: var(--color-background-hover, #f5f5f5); }
}
.files-next-menu button.files-next-menu__item--danger {
	color: #d32f2f;
	.icon { color: #d32f2f; }
}

// Mobile bottom sheet
.files-next-sheet {
	position: fixed;
	inset: 0;
	z-index: 100000;
}
.files-next-sheet__scrim {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	animation: fn-sheet-fade 0.15s ease-out;
}
.files-next-sheet__panel {
	position: absolute;
	left: 0;
	right: 0;
	bottom: 0;
	max-height: 80vh;
	overflow-y: auto;
	padding: 8px 12px calc(16px + env(safe-area-inset-bottom, 0px));
	background: var(--color-main-background, #fff);
	border-radius: 20px 20px 0 0;
	box-shadow: 0 -6px 30px rgba(0, 0, 0, 0.22);
	animation: fn-sheet-slide 0.2s ease-out;
	transition: transform 0.05s linear;
}
.files-next-sheet__handle {
	width: 40px;
	height: 4px;
	margin: 6px auto 10px;
	border-radius: 3px;
	background: var(--color-border-maxcontrast, #ccc);
}
.files-next-sheet__head {
	padding: 4px 8px 12px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 6px;
}
.files-next-sheet__name {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 8px;
}
.files-next-sheet__sub {
	display: flex;
	align-items: center;
	gap: 10px;
}
.files-next-sheet__req {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}
.files-next-sheet__sep {
	height: 1px;
	margin: 6px 4px;
	background: var(--color-border);
}
.files-next-sheet button.files-next-sheet__item {
	all: unset;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: 16px;
	width: 100%;
	min-height: 44px;
	padding: 12px 10px;
	border-radius: 12px;
	color: var(--color-main-text);
	font-size: 16px;
	cursor: pointer;

	.icon { width: 22px; height: 22px; fill: currentColor; color: var(--color-text-maxcontrast); flex: 0 0 auto; }

	&:hover, &:focus-visible { background: var(--color-background-hover, #f5f5f5); }
}
.files-next-sheet button.files-next-sheet__item--danger {
	color: #d32f2f;
	.icon { color: #d32f2f; }
}
.files-next-sheet button.files-next-sheet__cancel {
	all: unset;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	min-height: 48px;
	margin-top: 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
	cursor: pointer;

	&:hover { background: var(--color-border); }
}

// Status pill in the sheet header (distinct class so it can't collide with the
// list's own scoped pill styles).
.fn-sheet-pill {
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
.fn-sheet-dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex: 0 0 auto;
	background: currentColor;
}
.fn-sheet-pill--draft { background: #f0f0f0; color: #616161; }
.fn-sheet-pill--ready { background: #fff6e0; color: #9a7500; }
.fn-sheet-pill--partial { background: #ecedf9; color: #3f51b5; }
.fn-sheet-pill--signed { background: #e6f4ea; color: #1e7d32; }
.fn-sheet-pill--neutral { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }

@keyframes fn-sheet-fade {
	from { opacity: 0; }
	to { opacity: 1; }
}
@keyframes fn-sheet-slide {
	from { transform: translateY(100%); }
	to { transform: translateY(0); }
}
</style>
