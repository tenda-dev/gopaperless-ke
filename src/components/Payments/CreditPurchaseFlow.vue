<template>
	<NcDialog
	name=""
	size="full"
	@closing="handleClose"
>

    <!-- Recovery loading -->
	<div
		v-if="isLoading"
		class="payment-modal-loading"
	>
		{{ loadingMessage }}
	</div>

	<!-- Recovery -->
	<PaymentRecoveryCard
		v-else-if="
			hasRecovery &&
			recoveryPayment &&
			!hasAcceptedRecovery
		"
		:payment="recoveryPayment"
		@resume="handleResume"
		@start-over="handleStartOver"
	/>

	<!-- Quantity selection -->
	<CreditQuantitySelector
		v-else-if="step === 'quantity'"
		:pricing="pricing"
		@selected="handleQuantitySelected"
	/>

	<!-- Payment execution -->
    <PaymentStep
	  v-else-if="step === 'payment'"
	  :payment-purpose="PAYMENT_PURPOSE"
      :sign-request-id="undefined"
      :sign-uuid="undefined"
      :product-code="productCode"
	  :pricing="pricing"
	  :initial-payment="hydratedPayment"
	  :quantity="quantity"
      @payment-success="onSuccess"
      @state-change="onStateChange"
	  @payment-runtime-invalid="onPaymentRuntimeInvalid"
    />

</NcDialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import PaymentStep from '@/components/Payments/PaymentStep.vue'
import PaymentRecoveryCard from '@/components/Payments/PaymentRecoveryCard.vue'
import CreditQuantitySelector from './CreditQuantitySelector.vue'
import { usePaymentRecovery } from '@/payment/usePaymentRecovery'
import { type PaymentState } from '@/payment/usePayment'
import type { HydratedPayment, PaymentPurpose } from '@/payment/types'
import { notifyInfo } from '@/services/toast'
import { useProductPricing } from '@/composables/useProductPricing';

type CreditPurchaseStep =
	| 'quantity'
	| 'payment'

const PAYMENT_PURPOSE: PaymentPurpose = 'credit_purchase'

const props = defineProps<{
	productCode: string
	preselectedQuantity?: number
}>()

const step = ref<CreditPurchaseStep>(
	props.preselectedQuantity
		? 'payment'
		: 'quantity',
)

const quantity = ref(
	props.preselectedQuantity ?? 1,
)

const emit = defineEmits([
	'close',
	'success'
])

const hydratedPayment = ref<HydratedPayment | null>(
	null
)

const {
	isChecking,
	hasRecovery,
	recoveryPayment,
	checkRecovery,
	resumeRecovery,
	discardRecovery,
} = usePaymentRecovery()

const pricing = useProductPricing(
	props.productCode
)

const hasAcceptedRecovery = ref(false)

// controls whether modal can close
const canClose = ref(false)

// track PaymentStep state to prevent closing during critical phases
const currentState = ref<PaymentState>('idle')


const isLoading = computed(() =>
	isChecking.value ||
	pricing.loadingProduct.value
)

const canRenderFlow = computed(() =>
	!isLoading.value &&
	pricing.isReady.value
)

const loadingMessage = computed(() => {

	if (isChecking.value) {
		return 'Checking payment session...'
	}

	if (pricing.loadingProduct.value) {
		return 'Loading pricing...'
	}

	return ''
})

const isQuantityLocked = computed(
	() => props.preselectedQuantity !== undefined,
)

function handleQuantitySelected(
	payload: { quantity: number }
) {
	quantity.value = payload.quantity

	step.value = 'payment'
}

onMounted(async () => {
	await checkRecovery({
		paymentPurpose: PAYMENT_PURPOSE,
		productCode: props.productCode,
	})

	if (props.preselectedQuantity) {
		handleQuantitySelected({ quantity: quantity.value })
	}
})

function handleClose() {
  // CRITICAL: block closing during payment execution
   if (
	currentState.value === 'processing' ||
	currentState.value === 'initiating' ||
	currentState.value === 'requesting' ||
	currentState.value === 'hydrating'
   ) {
	notifyInfo({
		message: `You are in the middle of an active payment session`
	})
	return
   }

  emit('close')
}

function handleResume() {
	const payment = resumeRecovery()

	if (!payment) {
		return
	}

	hydratedPayment.value = payment

	hasAcceptedRecovery.value = true

	step.value = 'payment'
}

function handleStartOver() {
	discardRecovery()

	hydratedPayment.value = null

	hasAcceptedRecovery.value = true

	step.value = props.preselectedQuantity
		? 'payment'
		: 'quantity'
}


// called when payment completes successfully
function onSuccess() {
  discardRecovery()
  canClose.value = true

  emit('success')
}

// receive state updates from PaymentStep
function onStateChange(state: typeof currentState.value) {
  currentState.value = state
}

function onPaymentRuntimeInvalid() {
   canClose.value = true
   emit('close')
}

</script>


<style lang="scss" scoped>
.payment-modal-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;

	min-height: 220px;

	font-size: 13px;
	font-weight: 500;
	letter-spacing: 0.01em;

	color: #64748b;

	&::before {
		content: '';

		width: 16px;
		height: 16px;

		border-radius: 50%;
		border: 2px solid rgba(100, 116, 139, 0.2);
		border-top-color: #64748b;

		animation: payment-modal-spin 0.7s linear infinite;
	}
}

@keyframes payment-modal-spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
