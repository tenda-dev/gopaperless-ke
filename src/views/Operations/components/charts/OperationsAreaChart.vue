<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-area">
		<svg
			class="ops-area__plot"
			:viewBox="`0 0 ${W} ${H}`"
			preserveAspectRatio="none"
			role="img"
			tabindex="0"
			:aria-label="label"
			@pointermove="onPointer"
			@pointerleave="active = null"
			@keydown.left.prevent="step(-1)"
			@keydown.right.prevent="step(1)"
			@keydown.esc="active = null"
			@blur="active = null">
			<defs>
				<linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" :stop-color="color" stop-opacity="0.22" />
					<stop offset="1" :stop-color="color" stop-opacity="0" />
				</linearGradient>
			</defs>
			<line v-for="g in 3"
				:key="g"
				class="ops-area__grid"
				:x1="PL"
				:x2="W - PR"
				:y1="PT + (IH * (g - 1)) / 2"
				:y2="PT + (IH * (g - 1)) / 2" />
			<text class="ops-area__axis" :x="PL" y="8">{{ yMax }}</text>
			<path :d="areaPath" :fill="`url(#${gradientId})`" />
			<path class="ops-area__line" :d="linePath" :stroke="color" />
			<circle v-if="points.length"
				:cx="points[points.length - 1].x"
				:cy="points[points.length - 1].y"
				r="3.2"
				:fill="color"
				class="ops-area__end" />
			<line v-if="active !== null"
				class="ops-area__cross"
				:stroke="color"
				:x1="points[active].x"
				:x2="points[active].x"
				:y1="PT"
				:y2="PT + IH" />
		</svg>
		<div v-if="active !== null" class="ops-area__tip" role="status" :style="{ left: tipLeft }">
			{{ shortDay(data[active].date) }} · {{ formatInt(data[active].count) }} {{ unit }}
		</div>
	</div>
</template>

<script setup lang="ts">
/**
 * Dependency-free area chart. Pointer, touch (via pointer events) and
 * keyboard (arrow keys on the focused chart) all drive the same crosshair
 * readout, so it works without hover.
 */
import { computed, ref } from 'vue'

import type { DailyPoint } from '../../types.ts'
import { formatInt, shortDay } from '../../utils/format.ts'

defineOptions({ name: 'OperationsAreaChart' })

const props = defineProps<{
	data: DailyPoint[]
	color: string
	unit: string
	label: string
}>()

const W = 320
const H = 118
const PT = 10
const PB = 18
const PL = 4
const PR = 6
const IH = H - PT - PB

const active = ref<number | null>(null)
// Unique per instance: SVG ids are document-global, so charts sharing one id would paint each other's gradient.
const gradientId = `ops-grad-${Math.random().toString(36).slice(2, 8)}`

const yMax = computed(() => Math.max(1, ...props.data.map((p) => p.count)))

// Plotted against 115% of the peak, leaving headroom above the highest day.
const points = computed(() => {
	const n = props.data.length
	const step = n > 1 ? (W - PL - PR) / (n - 1) : 0
	return props.data.map((p, i) => ({
		x: PL + i * step,
		y: PT + IH - (p.count / (yMax.value * 1.15)) * IH,
	}))
})

const linePath = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' '))
const areaPath = computed(() => {
	const last = points.value[points.value.length - 1]
	return last ? `${linePath.value} L${last.x.toFixed(1)} ${PT + IH} L${PL} ${PT + IH} Z` : ''
})
const tipLeft = computed(() => (active.value === null ? '0' : `${(points.value[active.value].x / W) * 100}%`))

function onPointer(e: PointerEvent) {
	const rect = (e.currentTarget as SVGElement).getBoundingClientRect()
	if (!rect.width || !props.data.length) {
		return
	}
	const ratio = (e.clientX - rect.left) / rect.width
	active.value = Math.max(0, Math.min(props.data.length - 1, Math.round(ratio * (props.data.length - 1))))
}

function step(delta: number) {
	if (!props.data.length) {
		return
	}
	const start = active.value ?? (delta > 0 ? -1 : props.data.length)
	active.value = Math.max(0, Math.min(props.data.length - 1, start + delta))
}
</script>

<style scoped lang="scss">
.ops-area {
	position: relative;

	&__plot {
		display: block;
		width: 100%;
		height: 118px;
		margin-top: 6px;
		overflow: visible;
		touch-action: pan-y;

		&:focus-visible {
			outline: 2px solid var(--ops-green);
			outline-offset: 2px;
			border-radius: 4px;
		}
	}

	&__grid {
		stroke: var(--ops-grid);
		stroke-width: 1;
	}

	&__axis {
		font-family: var(--ops-font-mono);
		font-size: 9px;
		fill: var(--ops-fg-faint);
	}

	&__line {
		fill: none;
		stroke-width: 2;
		stroke-linejoin: round;
		stroke-linecap: round;
		vector-effect: non-scaling-stroke;
	}

	&__end {
		stroke: var(--ops-surface);
		stroke-width: 2;
	}

	&__cross {
		stroke-width: 1;
		stroke-dasharray: 3 3;
	}

	&__tip {
		position: absolute;
		top: -4px;
		transform: translateX(-50%);
		pointer-events: none;
		white-space: nowrap;
		padding: 4px 8px;
		border-radius: 6px;
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--color-main-background);
		background: var(--color-main-text);
	}
}
</style>
