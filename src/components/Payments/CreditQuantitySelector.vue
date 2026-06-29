<template>
		<div class="credits-modal">

			<!-- Header -->
			<div class="credits-modal__header">
				<h2>Choose your credit pack</h2>

				<p>
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

				<div class="credits-modal__tray">

					<button v-for="option in quantityOptions" :key="option" type="button" class="quantity-pill" :class="{
						'quantity-pill--active':
							quantity === option,
					}" @click="selectQuantity(option)">
						{{ option }}
					</button>

				</div>

				<Transition name="price-fade" mode="out-in">

					<div :key="quantity" class="credits-modal__price">
						{{ quantity }} credits · {{ formattedTotal }}
					</div>

				</Transition>

				<button type="button" class="credits-modal__cta" @click="handleContinue">
					Continue to Payment
				</button>

				<p class="credits-modal__hint">
					Credits are added immediately after payment and remain available until used.
				</p>

			</template>

		</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { type ProductPricing } from '@/composables/useProductPricing'

const props = defineProps<{
	pricing: ProductPricing
}>()

const emit = defineEmits<{
	(
		event: 'selected',
		payload: {
			quantity: number
		}
	): void

	(
		event: 'close'
	): void
}>()

const quantityOptions = [
	5,
	10,
	15,
	20,
	50,
]

const {
  unitPrice,
  uses,
  currency,
  loadingProduct,
  error,
  reload
} = props.pricing

const quantity = ref(5)

function selectQuantity(value: number) {
	quantity.value = value
}

const totalAmount = computed(() => {
	return unitPrice.value * quantity.value
})

const totalUses = computed(() => {
	return uses.value * quantity.value
})

const formattedTotal = computed(() => {

	const majorUnits =
		totalAmount.value / 100

	return new Intl.NumberFormat(
		'en-KE',
		{
			style: 'currency',
			currency: currency.value ?? 'KES',
		}
	).format(majorUnits)
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
	gap: 28px;

	padding: 8px 4px;

	&__header {
		display: flex;
		flex-direction: column;
		gap: 10px;

		h2 {
			margin: 0;

			font-size: 22px;
			font-weight: 700;
			letter-spacing: -0.02em;

			color: #111827;
		}

		p {
			margin: 0;

			font-size: 14px;
			line-height: 1.6;

			color: #6b7280;
		}
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
		gap: 4px;
		padding: 10px;
		background: #f3f4f6;
		border-radius: 14px;
	}

	& .credits-modal__summary {
		display: flex;
		flex-direction: column;
		gap: 14px;

		padding: 18px;

		border-radius: 18px;

		background: #f8fafc;
		border: 1px solid #e5e7eb;
	}

	&__price {
		text-align: center;

		font-size: 22px;
		font-weight: 600;

		color: #111827;
	}

	&__cta {
		padding: 12px 20px;
		border: none;
		border-radius: 12px;

		background-color: #04d56d;

		color: white;

		font-size: 15px;
		font-weight: 600;

		cursor: pointer;

		transition:
			background 160ms ease;
	}

	&__cta:hover {
		background-color: #05e87a;
	}

	&__hint {
		margin: 0;

		text-align: center;

		font-size: 12px;

		color: #9ca3af;
	}
}

.quantity-pill {
	height: 42px;
	flex: 1;
	border: none;
	border-radius: 12px;

	background: transparent;

	font-size: 15px;
	font-weight: 600;

	color: #374151;

	cursor: pointer;

	transition:
		background-color 220ms ease,
		box-shadow 220ms ease,
		transform 220ms ease;
}

.quantity-pill--active {
	background: white;
	box-shadow:
		0 2px 8px rgba(0,0,0,.08);
	border: none;
	background: white;

	transform: translateY(-1px);

	box-shadow:
		0 4px 12px rgba(0,0,0,.08);
}

.price-fade-enter-active,
.price-fade-leave-active {
	transition:
		opacity 180ms ease,
		transform 180ms ease;
}

.price-fade-enter-from {
	opacity: 0;
	transform: translateY(6px);
}

.price-fade-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}
</style>
