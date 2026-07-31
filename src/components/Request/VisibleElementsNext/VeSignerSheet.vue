<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  VeSignerSheet — mobile-only bottom sheet.
  -->
<template>
	<section
		class="sheet"
		:class="{ 'sheet--adjust': adjusting, 'sheet--dragging': dragging }"
		:style="{ height: sheetHeightPx }"
		role="dialog"
		:aria-label="t('libresign', 'Signature controls')">
		<!-- Scrim pushes the document back a layer while expanded. -->
		<div class="sheet__scrim" :class="{ 'sheet__scrim--on': snap === 'expanded' && !adjusting }" aria-hidden="true" />

		<div
			class="sheet__grabber"
			role="button"
			tabindex="0"
			:aria-label="t('libresign', 'Drag to expand or collapse the controls')"
			@pointerdown="onGrabDown"
			@pointermove="onGrabMove"
			@pointerup="onGrabUp"
			@pointercancel="onGrabUp"
			@keydown="onGrabKey">
			<span class="sheet__grip" />
			<span v-if="overlapText" class="sheet__alert" :aria-label="t('libresign', 'Overlap detected')" />
		</div>

		<!-- Adjust bar: box has been dropped, drag it into position. -->
		<div v-if="adjusting" class="sheet__adjust">
			<span class="sheet__adjust-text">{{ t('libresign', 'Drag the box to position') }}</span>
			<VeButton variant="primary" small @click="$emit('done')">
				{{ t('libresign', 'Done') }}
			</VeButton>
		</div>

		<template v-else>
			<div class="sheet__body">
				<div class="sheet__head">
					<span class="sheet__title">{{ snap === 'expanded' ? t('libresign', 'Signers') : documentName }}</span>
					<VeCompletenessPill :placed="placedCount" :total="signerCount" />
				</div>

				<div v-if="overlapText" class="sheet__overlap">{{ overlapText }}</div>

				<!-- Peek: horizontal chips (short names, fades at the edge). -->
				<div v-if="snap === 'peek'" class="sheet__chips">
					<VeSignerSelect v-for="signer in signers"
						:key="'chip-' + signer.signRequestId"
						:signer="signer"
						:palette="colorFor(signer.signRequestId)"
						:placed-count="countBySigner[signer.signRequestId] || 0"
						:show-review="false"
						layout="chip"
						@select="$emit('select', $event)" />
				</div>

				<!-- Expanded: divider-separated rows with colour accent + eye. -->
				<div v-else class="sheet__detail">
					<VeSignerSelect v-for="signer in signers"
						:key="'row-' + signer.signRequestId"
						:signer="signer"
						:palette="colorFor(signer.signRequestId)"
						:placed-count="countBySigner[signer.signRequestId] || 0"
						:reviewing="reviewingSignerId === signer.signRequestId"
						:overlapping="overlappingSigners.has(signer.signRequestId)"
						layout="row"
						@select="$emit('select', $event)"
						@review="$emit('review', $event)" />
				</div>
			</div>

			<div class="sheet__footer">
				<VeButton v-if="canSave"
					:variant="anyEmpty ? 'secondary' : 'primary'"
					wide
					@click="$emit('save')">
					{{ anyEmpty ? t('libresign', 'Save anyway') : t('libresign', 'Save') }}
				</VeButton>
			</div>
		</template>
	</section>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import VeButton from './VeButton.vue'
import VeCompletenessPill from './VeCompletenessPill.vue'
import VeSignerSelect from './VeSignerSelect.vue'
import type { Palette } from './composables/useSignerColors'
import type { SignerSummaryRecord } from '../../../types/index'

defineOptions({
	name: 'VeSignerSheet',
})

const props = withDefaults(defineProps<{
	documentName: string
	signers: SignerSummaryRecord[]
	colorFor: (signRequestId: number) => Palette
	countBySigner: Record<number, number>
	placedCount: number
	signerCount: number
	anyEmpty?: boolean
	overlapText?: string
	overlappingSigners?: Set<number>
	reviewingSignerId?: number | null
	canSave?: boolean
	/** True while a just-dropped box is being dragged into position. */
	adjusting?: boolean
}>(), {
	anyEmpty: false,
	overlapText: '',
	overlappingSigners: () => new Set<number>(),
	reviewingSignerId: null,
	canSave: true,
	adjusting: false,
})

defineEmits<{
	(event: 'select', signer: SignerSummaryRecord): void
	(event: 'review', signer: SignerSummaryRecord): void
	(event: 'save'): void
	(event: 'done'): void
}>()

const ADJUST_HEIGHT = 74
const PEEK_HEIGHT = 200

const snap = ref<'peek' | 'expanded'>('peek')
const dragging = ref(false)
const expandedHeight = ref(480)
const currentHeight = ref(PEEK_HEIGHT)

let startY = 0
let startHeight = PEEK_HEIGHT

function recomputeExpanded() {
	expandedHeight.value = Math.max(PEEK_HEIGHT + 140, Math.round(window.innerHeight * 0.62))
	if (snap.value === 'expanded' && !dragging.value) {
		currentHeight.value = expandedHeight.value
	}
}

const sheetHeightPx = computed(() => `${props.adjusting ? ADJUST_HEIGHT : currentHeight.value}px`)

function clamp(value: number, min: number, max: number): number {
	return Math.min(max, Math.max(min, value))
}

function onGrabDown(event: PointerEvent) {
	if (props.adjusting) {
		return
	}
	dragging.value = true
	startY = event.clientY
	startHeight = currentHeight.value
	;(event.target as HTMLElement)?.setPointerCapture?.(event.pointerId)
}

function onGrabMove(event: PointerEvent) {
	if (!dragging.value) {
		return
	}
	currentHeight.value = clamp(startHeight + (startY - event.clientY), PEEK_HEIGHT, expandedHeight.value)
}

function onGrabUp() {
	if (!dragging.value) {
		return
	}
	dragging.value = false
	const midpoint = (PEEK_HEIGHT + expandedHeight.value) / 2
	snap.value = currentHeight.value > midpoint ? 'expanded' : 'peek'
	currentHeight.value = snap.value === 'expanded' ? expandedHeight.value : PEEK_HEIGHT
}

function onGrabKey(event: KeyboardEvent) {
	if (event.key === 'ArrowUp') {
		event.preventDefault()
		snap.value = 'expanded'
		currentHeight.value = expandedHeight.value
	} else if (event.key === 'ArrowDown') {
		event.preventDefault()
		snap.value = 'peek'
		currentHeight.value = PEEK_HEIGHT
	}
}

watch(() => props.adjusting, (isAdjusting) => {
	if (!isAdjusting) {
		snap.value = 'peek'
		currentHeight.value = PEEK_HEIGHT
	}
})

onMounted(() => {
	recomputeExpanded()
	window.addEventListener('resize', recomputeExpanded)
})
onBeforeUnmount(() => {
	window.removeEventListener('resize', recomputeExpanded)
})
</script>

<style lang="scss" scoped>
.sheet {
	position: absolute;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 20;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border-top-left-radius: 16px;
	border-top-right-radius: 16px;
	box-shadow: 0 -6px 24px rgba(0, 0, 0, 0.18);
	padding-bottom: env(safe-area-inset-bottom, 0);

	&:not(.sheet--dragging) {
		transition: height 200ms ease;
	}

	// Scrim over the document above the sheet (expanded only).
	&__scrim {
		position: absolute;
		left: 0;
		right: 0;
		bottom: 100%;
		height: 100vh;
		background-color: rgba(0, 0, 0, 0.28);
		opacity: 0;
		pointer-events: none;
		transition: opacity 200ms ease;

		&--on {
			opacity: 1;
		}
	}

	&__grabber {
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		position: relative;
		height: 24px;
		cursor: grab;
		touch-action: none;

		&:active {
			cursor: grabbing;
		}
	}

	&__grip {
		width: 40px;
		height: 5px;
		border-radius: 999px;
		background-color: var(--color-border-dark, #b9b9b9);
	}

	&__alert {
		position: absolute;
		top: 8px;
		right: 16px;
		width: 9px;
		height: 9px;
		border-radius: 50%;
		background-color: var(--color-error, #c6533f);
	}

	&__adjust {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 4px 14px 10px;
	}

	&__adjust-text {
		font-size: 14px;
		color: var(--color-text-maxcontrast);
	}

	&__body {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 2px 0 8px;
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 0 14px;
	}

	&__title {
		flex: 1;
		min-width: 0;
		font-size: 16px;
		font-weight: 700;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__overlap {
		margin: 0 14px;
		font-size: 12px;
		color: var(--color-error, #c6533f);
		background-color: rgba(198, 83, 63, 0.1);
		border-radius: 6px;
		padding: 6px 8px;
	}

	&__chips {
		display: flex;
		flex-direction: row;
		gap: 8px;
		overflow-x: auto;
		padding: 0 14px 2px;
		// Fade at the right edge instead of a hard clip.
		mask-image: linear-gradient(to right, #000 calc(100% - 24px), transparent);
		-webkit-mask-image: linear-gradient(to right, #000 calc(100% - 24px), transparent);
	}

	&__detail {
		display: flex;
		flex-direction: column;
	}

	&__footer {
		flex-shrink: 0;
		padding: 8px 14px 12px;
		border-top: 1px solid var(--color-border);
		background-color: var(--color-main-background);
	}
}
</style>
