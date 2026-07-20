<template>
	<div class="credits-modal">

		<!-- Header -->
		<div class="credits-modal__header">
			<h2 class="credits-modal__title">Choose your credit pack</h2>
			<p class="credits-modal__subtitle">
				Select the number of signing credits you'd like to purchase.
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

		<!-- Content -->
		<template v-else>

			<div
				class="credits-modal__tray"
				role="radiogroup"
				aria-label="Number of credits"
			>
				<button
					v-for="option in quantityOptions"
					:key="option"
					type="button"
					class="quantity-pill"
					:class="{
						'quantity-pill--active': quantity === option,
					}"
					:disabled="option < minQuantity"
					:title="
						option < minQuantity
							? `Minimum purchase is ${minQuantity} credits`
							: undefined
					"
					role="radio"
					:aria-checked="quantity === option"
					@click="selectQuantity(option)"
				>
					{{ option }}
				</button>
			</div>

			<div class="credits-modal__price">
				<Transition name="price-swap" mode="out-in">
					<div :key="quantity" class="credits-modal__price-inner">
						<span class="credits-modal__price-amount">{{ formattedTotal }}</span>
						<span class="credits-modal__price-meta">for {{ quantity }} credits</span>
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
				Credits are added immediately after payment and remain available until used.
			</p>

		</template>

	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ProductPricing } from '@/composables/useProductPricing'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowRight } from '@mdi/js';

const props = withDefaults(defineProps<{
	pricing: ProductPricing
	minQuantity?: number
}>(), {
	minQuantity: 0,
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

const QUANTITY_OPTIONS = [
	5,
	10,
	15,
	20,
	50,
	70,
	100,
	150,
	200,
	250,
	300,
	350,
] as const

function resolveInitialQuantity(minQuantity: number): number {
	return (
		QUANTITY_OPTIONS.find(
			option => option >= minQuantity,
		) ?? QUANTITY_OPTIONS.at(-1)!
	)
}

const quantityOptions = QUANTITY_OPTIONS

const quantity = ref(
	resolveInitialQuantity(props.minQuantity),
)

function selectQuantity(value: number) {
	if (value < props.minQuantity) {
		return
	}

	quantity.value = value
}

const totalAmount = computed(() =>
	unitPrice.value * quantity.value,
)

const formattedTotal = computed(() => {
	return new Intl.NumberFormat('en-KE', {
		style: 'currency',
		currency: currency.value ?? 'KES',
	}).format(totalAmount.value / 100)
})

function handleContinue() {
	emit('selected', {
		quantity: quantity.value,
	})

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

	&__loading,
	&__error {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;

		min-height: 180px;

		font-size: 14px;
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

		&__tray {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			padding: 10px;
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

.quantity-pill {
	flex: 1 1 calc(20% - 6px);
	min-width: 58px;

	height: 42px;

	border: none;
	border-radius: 10px;

	background: transparent;

	font-size: 14px;
	font-weight: 600;

	color: #374151;

	cursor: pointer;

	transition:
		background-color 180ms ease,
		box-shadow 180ms ease,
		transform 180ms ease,
		color 180ms ease,
		opacity 180ms ease;

	&:hover:not(.quantity-pill--active):not(:disabled) {
		background: rgba(255,255,255,.65);
		color: #111827;
	}

	&:active:not(:disabled) {
		transform: scale(.97);
	}

	&--active {
		background: white;
		color: #111827;

		transform: translateY(-1px);

		box-shadow:
			0 4px 12px rgba(0,0,0,.08);
	}

	&:disabled {
		cursor: not-allowed;
		opacity: .35;
		color: #9ca3af;
		filter: grayscale(.25);
		background: transparent;
		transform: none;
		box-shadow: none;
	}

	&:disabled:hover {
		background: transparent;
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

@media (max-width: 600px) {
	.quantity-pill {
		flex: 1 1 calc(25% - 6px);
	}
}

@media (max-width: 420px) {
	.quantity-pill {
		flex: 1 1 calc(33.333% - 6px);
		font-size: 13px;
		height: 40px;
	}
}

@media (max-width: 340px) {
	.quantity-pill {
		flex: 1 1 calc(50% - 6px);
	}
}
</style>
