<template>
	<div class="payment-return">
		<div class="content">
			<NcLoadingIcon :size="48" />

			<h2>Verifying your payment...</h2>
			<p>Contacting payment provider...</p>

			<p v-if="status === 'loading'">
				Please wait while we confirm your transaction.
			</p>

			<p v-if="status === 'error'" class="error">
				Something went wrong while verifying your payment. Redirecting you back...
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { verifyPayment } from '@/payment/api'
import { getPaymentFromUrl, clearPaymentParamsFromUrl } from '@/payment/helpers'

import { notifySuccess, notifyError } from '@/services/toast'
import { usePaymentContextStore } from '@/store/paymentContext'

const router = useRouter()

const status = ref<'loading' | 'error'>('loading')

let hasRun = false

onMounted(async () => {
	if (hasRun) return
	hasRun = true
	const { transactionToken, signUuid } = getPaymentFromUrl()

	const paymentContextStore = usePaymentContextStore()
	paymentContextStore.clear()

	// The callback URL is the authoritative source for which document
	// the payment belongs to. signUuid/PnrID/CompanyRef all point to the
	// sign request UUID that DPO was given for this payment.
	const resolvedSignUuid = signUuid || null

	// Invalid return
	if (!transactionToken || !resolvedSignUuid) {
		clearPaymentParamsFromUrl()

		notifyError({ message: 'Invalid payment return', important: true })
		router.replace({ name: 'DefaultPageErrorExternal' })
		return
	}

	console.log('[PaymentReturn] Verifying payment with token', transactionToken, 'and signUuid', resolvedSignUuid)

	// Fallback to the URL value; will be overwritten with the backend's canonical UUID after verification.
	let targetSignUuid = resolvedSignUuid

	try {
		const res = await verifyPayment(transactionToken)

		// Prefer the backend's canonical sign request UUID if provided.
		// This protects us if the provider ever omits or mislabels the URL param.
		targetSignUuid = res.signRequestUuid || resolvedSignUuid

		if (res.status === 'SUCCESS') {
			notifySuccess({
				message: 'Payment successful',
			})

			clearPaymentParamsFromUrl()

			// Redirect back with retry flag
			router.replace({
				name: 'SignPDFExternal',
				params: {
					uuid: targetSignUuid
				},
				query: { retrySign: 'true' },
			})

			return
		}

		// Failed
		status.value = 'error'
		clearPaymentParamsFromUrl()
		notifyError({
			message: 'Payment failed',
			important: true,
		})

		router.replace({
			name: 'SignPDFExternal',
			params: {
				uuid: targetSignUuid
			},
			query: { paymentFailed: 'true' },
		})

	} catch (err) {
		console.error('[PaymentReturn] verification failed', err)

		status.value = 'error'

		notifyError({
			message: 'Could not verify payment',
			important: true,
		})

		// Clean URL
		clearPaymentParamsFromUrl()

		router.replace({
			name: 'SignPDFExternal',
			params: {
				uuid: targetSignUuid
			},
			query: { paymentFailed: 'true' },
		})

	}
})
</script>

<style scoped>
.payment-return {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.content {
	text-align: center;
}

h2 {
	margin-top: 16px;
	font-size: 18px;
}

p {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
}
</style>
