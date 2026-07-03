<template>
	<div class="credits-modal">

		<div class="credits-modal__header">
			<h2 class="credits-modal__title">Choose your credit pack</h2>
			<p class="credits-modal__subtitle">
				Select the number of signing credits you'd like to purchase.
			</p>
		</div>

		<div v-if="loadingProduct" class="credits-modal__state">
			<span class="spinner spinner--sm" />
			<span>Loading pricing…</span>
		</div>

		<div v-else-if="error" class="credits-modal__state credits-modal__state--error">
			<p class="credits-modal__error-text">{{ error }}</p>
			<button type="button" class="credits-modal__retry" @click="reload">
				Try again
			</button>
		</div>

		<template v-else>

			<!--
				Segmented pill tray. Each pill flexes equally so the
				row fills the width at any option count. The active
				pill lifts with a white fill + soft shadow.
			-->
			<div class="credits-modal__tray" role="radiogroup" aria-label="Number of credits">
				<button
					v-for="option in quantityOptions"
					:key="option"
					type="button"
					class="quantity-pill"
					:class="{ 'quantity-pill--active': quantity === option }"
					role="radio"
					:aria-checked="quantity === option"
					@click="selectQuantity(option)"
				>
					{{ option }}
				</button>
			</div>

			<!--
				Price panel. The amount swaps with a directional fade
				keyed on quantity so the number feels like it updates
				in place rather than re-rendering.
			-->
			<div class="credits-modal__price">
				<Transition name="price-swap" mode="out-in">
					<div :key="quantity" class="credits-modal__price-inner">
						<span class="credits-modal__price-amount">{{ formattedTotal }}</span>
						<span class="credits-modal__price-meta">for {{ quantity }} credits</span>
					</div>
				</Transition>
			</div>

			<!-- Primary CTA — carries the live total for context -->
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
				Credits are added immediately after payment and remain available until used.
			</p>

		</template>

	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowRight } from '@mdi/js'
import { type ProductPricing } from '@/composables/useProductPricing'

const props = defineProps<{
	pricing: ProductPricing
}>()

const emit = defineEmits<{
	(event: 'selected', payload: { quantity: number }): void
	(event: 'close'): void
}>()

const {
	unitPrice,
	uses,
	currency,
	loadingProduct,
	error,
	reload,
} = props.pricing

const quantityOptions = [5, 10, 15, 20, 50]

const quantity = ref(5)

function selectQuantity(value: number) {
	quantity.value = value
}

const totalAmount = computed(() => unitPrice.value * quantity.value)

const formattedTotal = computed(() => {
	const majorUnits = totalAmount.value / 100
	return new Intl.NumberFormat('en-KE', {
		style: 'currency',
		currency: currency.value!,
	}).format(majorUnits)
})

function handleContinue() {
	emit('selected', { quantity: quantity.value })
	handleClose()
}

function handleClose() {
	emit('close')
}
</script>

<style lang="scss" scoped>
.credits-modal {
	display: flex;
	flex-direction: column;
	gap: 22px;
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

	&__state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		min-height: 180px;
		font-size: 13.5px;
		color: #64748b;

		&--error {
			color: #991b1b;
		}
	}

	&__error-text {
		margin: 0;
		text-align: center;
		line-height: 1.5;
	}

	&__retry {
		border: none;
		border-radius: 10px;
		padding: 9px 16px;
		cursor: pointer;
		font-size: 13px;
		font-weight: 600;
		color: #374151;
		background: #f1f5f9;
		transition: background 160ms ease;

		&:hover { background: #e2e8f0; }
		&:active { transform: scale(0.98); }
	}

	&__tray {
		display: flex;
		gap: 4px;
		padding: 5px;
		background: #f3f4f6;
		border-radius: 13px;
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
		padding: 12px 20px;
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
		font-size: 11.5px;
		line-height: 1.5;
		color: #9ca3af;
	}
}

.quantity-pill {
	flex: 1;
	height: 42px;
	border: none;
	border-radius: 10px;
	background: transparent;
	font-size: 14.5px;
	font-weight: 600;
	color: #374151;
	cursor: pointer;
	transition:
		background-color 200ms cubic-bezier(0.4, 0, 0.2, 1),
		box-shadow 200ms cubic-bezier(0.4, 0, 0.2, 1),
		transform 200ms cubic-bezier(0.34, 1.56, 0.64, 1),
		color 200ms ease;

	&:hover:not(.quantity-pill--active) {
		background: rgba(255, 255, 255, 0.6);
		color: #111827;
	}

	&:active:not(.quantity-pill--active) {
		transform: scale(0.96);
	}

	// Active: white lift with a soft shadow + slight rise
	&--active {
		background: #fff;
		color: #111827;
		transform: translateY(-1px);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
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

.spinner {
	display: inline-block;
	border-radius: 50%;
	border-style: solid;
	border-color: transparent;
	border-top-color: #64748b;
	animation: spin 0.65s linear infinite;
	flex-shrink: 0;
}

.spinner--sm {
	width: 15px;
	height: 15px;
	border-width: 2px;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

@media (max-width: 400px) {
	.credits-modal {
		gap: 18px;

		&__title { font-size: 18px; }

		&__tray {
			gap: 3px;
			padding: 4px;
		}

		&__price-amount { font-size: 21px; }
	}

	.quantity-pill {
		height: 40px;
		font-size: 13.5px;
		border-radius: 9px;
	}
}

// Very narrow (≤340px): 5 pills at 50 can crowd — allow the tray
// to wrap to two rows rather than shrinking text below legibility.
@media (max-width: 340px) {
	.credits-modal__tray {
		flex-wrap: wrap;
	}

	.quantity-pill {
		flex: 1 1 calc(33.333% - 3px);
		min-width: 0;
	}
}
</style>
