<template>
	<div class="credits-modal">

		<!-- Header -->
		<div class="credits-modal__header">
			<h2 class="credits-modal__title">Choose how many certified signatures you'd like to buy</h2>
			<p class="credits-modal__subtitle">
				Slide to choose an amount. Popular bundles snap into place.
			</p>
		</div>

		<!-- Loading -->
		<div v-if="loadingProduct" class="credits-modal__loading">
			Loading pricing…
		</div>

		<!-- Error -->
		<div v-else-if="error" class="credits-modal__error">
			<p>{{ error }}</p>

			<button type="button" @click="reload">
				Try again
			</button>
		</div>

		<!-- Floor exceeds cap: unsatisfiable minimum, surfaced rather than silently capped -->
		<div v-else-if="hasFloorConflict" class="credits-modal__error">
			<p>
				You need to buy at least {{ props.minQuantity }} certified signatures, but you can buy at most {{ maxV }}
			</p>
		</div>

		<!-- Content -->
		<template v-else>

			<!-- Live value readout -->
			<div class="credits-modal__count">
				<span class="credits-modal__count-value">{{ quantity }}</span>
				<span class="credits-modal__count-unit">
					certified {{ quantity === 1 ? 'signature' : 'signatures' }}
				</span>
			</div>

			<div class="credits-modal__mode">
				<span
					class="credits-modal__mode-pill"
					:class="isSnapped
						? 'credits-modal__mode-pill--pack'
						: 'credits-modal__mode-pill--custom'"
				>
					{{ isSnapped ? 'Popular pack' : 'Custom amount' }}
				</span>
			</div>

			<!-- Slider -->
			<div class="credits-modal__slider">
				<div
					ref="trackRef"
					class="slider-track"
					@pointerdown="onPointerDown"
				>
					<!-- Dead zone below the minimum -->
					<div
						class="slider-track__dead"
						:style="{ width: deadPct * 100 + '%' }"
					/>

					<!-- Active fill -->
					<div
						class="slider-track__fill"
						:class="{ 'slider-track__fill--snap': !dragging }"
						:style="{
							left: fillLeft + '%',
							width: fillWidth + '%',
						}"
					/>

					<!-- Preset anchors -->
					<template
						v-for="snap in activeSnaps"
						:key="snap"
					>
						<span
							class="slider-track__tick"
							:class="{ 'slider-track__tick--on': snap === quantity }"
							:style="{ left: pct(snap) * 100 + '%' }"
							@pointerdown.stop
							@click.stop="selectSnap(snap)"
						/>
						<span
							class="slider-track__label"
							:class="{ 'slider-track__label--on': snap === quantity }"
							:style="{ left: pct(snap) * 100 + '%' }"
							@pointerdown.stop
							@click.stop="selectSnap(snap)"
						>
							{{ snap }}
						</span>
					</template>

					<!-- Thumb -->
					<div
						class="slider-track__thumb"
						:class="{ 'slider-track__thumb--snap': !dragging }"
						:style="{ left: thumbPct * 100 + '%' }"
						tabindex="0"
						role="slider"
						:aria-valuemin="minV"
						:aria-valuemax="maxV"
						:aria-valuenow="quantity"
						:aria-valuetext="ariaValueText"
						aria-label="Choose the number of certified signatures to buy"
						@keydown="onKeydown"
					/>
				</div>
			</div>

			<!-- Price -->
			<div class="credits-modal__price">
				<Transition
					:name="animatePrice ? 'price-swap' : ''"
					mode="out-in"
				>
					<div :key="quantity" class="credits-modal__price-inner">
						<span class="credits-modal__price-amount">{{ formattedTotal }}</span>
						<span class="credits-modal__price-meta">
							Includes {{ quantity }} certified signature{{ quantity === 1 ? '' : 's' }}
						</span>
					</div>
				</Transition>
			</div>

			<button
				type="button"
				class="credits-modal__cta"
				@click="handleContinue"
			>
				<span class="credits-modal__cta-label">Continue to payment</span>
				<NcIconSvgWrapper
					class="credits-modal__cta-arrow"
					:path="mdiArrowRight"
					:size="16"
				/>
			</button>

			<p class="credits-modal__hint">
				A certified signature lets you use your personal digital certificate to sign documents.
			</p>

		</template>

	</div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import type { ProductPricing } from '@/composables/useProductPricing'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowRight } from '@mdi/js'
import { useCreditSlider } from '@/composables/useCreditSlider'

const props = withDefaults(defineProps<{
	pricing: ProductPricing

	/** Hard floor. 0 / undefined ⇒ no floor. */
	minQuantity?: number

	/** Sellable ceiling. */
	maxQuantity?: number

	/** Preferred packs to snap to (e.g. product-config driven). */
	snapPoints?: readonly number[]

	/** Optional starting value; clamped into the valid range. */
	initialQuantity?: number
}>(), {
	minQuantity: 0,
	maxQuantity: 100,
})

const emit = defineEmits<{
	(event: 'selected', payload: { quantity: number }): void
	(event: 'close'): void
}>()

const {
	unitPrice,
	currency,
	loadingProduct,
	error,
	reload,
} = props.pricing

const {
	quantity,
	dragging,
	animatePrice,
	minV,
	maxV,
	activeSnaps,
	hasFloorConflict,
	deadPct,
	thumbPct,
	fillLeft,
	fillWidth,
	isSnapped,
	ariaValueText,
	pct,
	beginDrag,
	dragTo,
	endDrag,
	cancelDrag,
	stepBy,
	toMin,
	toMax,
	selectSnap,
	jumpToNextSnap,
	jumpToPrevSnap,
} = useCreditSlider({
	minQuantity: () => props.minQuantity,
	maxQuantity: () => props.maxQuantity,
	snapPoints: () => props.snapPoints,
	initialQuantity: () => props.initialQuantity,
})

// --- pricing (unchanged) ----------------------------------------------------

const totalAmount = computed(() => unitPrice.value * quantity.value)

const formattedTotal = computed(() =>
	new Intl.NumberFormat('en-KE', {
		style: 'currency',
		currency: currency.value ?? 'KES',
	}).format(totalAmount.value / 100),
)

// --- pointer → position -----------------------------------------------------

const trackRef = ref<HTMLElement | null>(null)

function pointerPct(event: PointerEvent): number {
	const el = trackRef.value
	if (!el) {
		return 0
	}
	const rect = el.getBoundingClientRect()
	return (event.clientX - rect.left) / rect.width
}

function onPointerDown(event: PointerEvent) {
	beginDrag(pointerPct(event))
	window.addEventListener('pointermove', onPointerMove)
	window.addEventListener('pointerup', onPointerUp)
}

function onPointerMove(event: PointerEvent) {
	event.preventDefault()
	dragTo(pointerPct(event))
}

function onPointerUp(event: PointerEvent) {
	endDrag(pointerPct(event))
	detachPointer()
}

function detachPointer() {
	window.removeEventListener('pointermove', onPointerMove)
	window.removeEventListener('pointerup', onPointerUp)
}

onBeforeUnmount(() => {
	cancelDrag()
	detachPointer()
})

// --- keyboard ---------------------------------------------------------------

function onKeydown(event: KeyboardEvent) {
	switch (event.key) {
	case 'ArrowRight':
	case 'ArrowUp':
		stepBy(1)
		break
	case 'ArrowLeft':
	case 'ArrowDown':
		stepBy(-1)
		break
	case 'PageUp':
		jumpToNextSnap()
		break
	case 'PageDown':
		jumpToPrevSnap()
		break
	case 'Home':
		toMin()
		break
	case 'End':
		toMax()
		break
	default:
		return
	}

	event.preventDefault()
}

// --- continue ---------------------------------------------------------------

function handleContinue() {
	emit('selected', { quantity: quantity.value })
	emit('close')
}
</script>

<style lang="scss" scoped>
.credits-modal {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 20px 10px;

	&__header {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__title {
		margin: 0;
		font-size: 19px;
		font-weight: 600;
		letter-spacing: -0.02em;
		color: #111827;
	}

	&__subtitle {
		margin: 0;
		font-size: 13px;
		line-height: 1.55;
		color: #6b7280;
	}

	&__loading,
	&__error {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;

		min-height: 180px;

		font-size: 14px;
		text-align: center;
		color: #64748b;

		button {
			border: none;
			border-radius: 12px;
			padding: 10px 16px;
			cursor: pointer;
			font-weight: 600;
			background: #f1f5f9;
		}
	}

	&__count {
		display: flex;
		align-items: baseline;
		justify-content: center;
		gap: 8px;
	}

	&__count-value {
		font-size: 40px;
		font-weight: 600;
		line-height: 1;
		letter-spacing: -0.02em;
		color: #111827;
		font-variant-numeric: tabular-nums;
	}

	&__count-unit {
		font-size: 14px;
		color: #9ca3af;
	}

	&__mode {
		display: flex;
		justify-content: center;
		margin-top: -8px;
	}

	&__mode-pill {
		font-size: 11px;
		font-weight: 600;
		padding: 3px 10px;
		border-radius: 999px;

		&--pack {
			background: #dcfce7;
			color: #047a45;
		}

		&--custom {
			background: #f1f5f9;
			color: #94a3b8;
		}
	}

	&__slider {
		padding: 22px 8px 34px;
	}

	&__price {
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 15px 0;
		background: #f8fafc;
		border: 0.5px solid #e2e8f0;
		border-radius: 12px;
		min-height: 62px;
	}

	&__price-inner {
		display: flex;
		align-items: baseline;
		gap: 8px;
	}

	&__price-amount {
		font-size: 23px;
		font-weight: 600;
		letter-spacing: -0.02em;
		color: #111827;
	}

	&__price-meta {
		font-size: 12.5px;
		color: #9ca3af;
	}

	&__cta {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 7px;
		padding: 8px 20px;
		border: none;
		border-radius: 11px;
		background-color: #04d56d;
		color: #073d1f;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		transition:
			background 160ms ease,
			transform 160ms ease,
			box-shadow 160ms ease;

		&:hover {
			background-color: #05e87a;
			transform: translateY(-1px);
			box-shadow: 0 4px 14px rgba(4, 213, 109, 0.32);
		}

		&:active {
			transform: scale(0.98);
			box-shadow: none;
		}
	}

	&__cta-arrow {
		display: flex !important;
		align-items: center;
		transition: transform 160ms ease;
	}

	&__cta:hover &__cta-arrow {
		transform: translateX(3px);
	}

	&__hint {
		margin: 0;
		text-align: center;
		font-size: 12px;
		color: #9ca3af;
	}
}

.slider-track {
	position: relative;
	height: 6px;
	border-radius: 999px;
	background: #eef2f6;
	border: 0.5px solid #e2e8f0;
	cursor: pointer;
	touch-action: none;

	&__dead {
		position: absolute;
		top: 0;
		left: 0;
		height: 100%;
		border-radius: 999px 0 0 999px;
		background: #dbe1e8;
	}

	&__fill {
		position: absolute;
		top: 0;
		height: 100%;
		border-radius: 999px;
		background: #04d56d;

		&--snap {
			transition:
				left 190ms cubic-bezier(0.22, 1, 0.36, 1),
				width 190ms cubic-bezier(0.22, 1, 0.36, 1);
		}
	}

	&__tick {
		position: absolute;
		top: 50%;
		width: 2px;
		height: 9px;
		transform: translate(-50%, -50%);
		background: #cbd5e1;
		border-radius: 2px;
		cursor: pointer;

		&--on {
			background: #04d56d;
			height: 12px;
		}
	}

	&__label {
		position: absolute;
		top: 15px;
		transform: translateX(-50%);
		font-size: 11px;
		white-space: nowrap;
		color: #9ca3af;
		cursor: pointer;
		user-select: none;

		&--on {
			color: #111827;
			font-weight: 600;
		}
	}

	&__thumb {
		position: absolute;
		top: 50%;
		width: 26px;
		height: 26px;
		border-radius: 50%;
		background: #fff;
		border: 2px solid #04d56d;
		transform: translate(-50%, -50%);
		cursor: grab;
		box-shadow: 0 2px 6px rgba(0, 0, 0, 0.14);

		&:active {
			cursor: grabbing;
		}

		&:focus-visible {
			outline: none;
			box-shadow: 0 0 0 4px rgba(4, 213, 109, 0.28);
		}

		&--snap {
			transition: left 190ms cubic-bezier(0.22, 1, 0.36, 1);
		}
	}
}

.price-swap-enter-active,
.price-swap-leave-active {
	transition:
		opacity 180ms ease,
		transform 180ms cubic-bezier(0.4, 0, 0.2, 1);
}

.price-swap-enter-from {
	opacity: 0;
	transform: translateY(6px);
}

.price-swap-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (max-width: 420px) {
	.slider-track__label {
		font-size: 10px;
	}
}
</style>
