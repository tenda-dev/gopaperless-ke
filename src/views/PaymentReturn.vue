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

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { verifyPayment } from '@/payment/api'
import {
	getPaymentFromUrl,
	clearPaymentParamsFromUrl,
} from '@/payment/helpers'

import { notifySuccess, notifyError } from '@/services/toast'
import { usePaymentContextStore } from '@/store/paymentContext'

const status = ref<'loading' | 'error'>('loading')

let hasRun = false

/**
 * Only allow redirects to the same application origin.
 *
 * The payment return URL is persisted by the backend and should
 * normally be an internal application URL/path.
 */
function redirectToReturnUrl(returnUrl: string | undefined) {
	if (!returnUrl) {
		window.location.replace('/')
		return
	}

	try {
		const url = new URL(returnUrl, window.location.origin)

		if (url.origin !== window.location.origin) {
			throw new Error('Invalid payment return URL')
		}

		window.location.replace(
			`${url.pathname}${url.search}${url.hash}`
		)
	} catch (err) {
		console.warn('[PaymentReturn] Invalid return URL', err)
		window.location.replace('/')
	}
}

onMounted(async () => {
	if (hasRun) return
	hasRun = true

	const { transactionToken } = getPaymentFromUrl()

	const paymentContextStore = usePaymentContextStore()
	paymentContextStore.reset()

	if (!transactionToken) {
		clearPaymentParamsFromUrl()

		notifyError({
			message: 'Invalid payment return',
			important: true,
		})

		window.location.replace('/')
		return
	}

	try {
		const res = await verifyPayment(transactionToken)

		clearPaymentParamsFromUrl()

		if (res.status === 'SUCCESS') {
			notifySuccess({
				message: 'Payment successful',
			})

			redirectToReturnUrl(res.returnUrl)

			return
		}

		status.value = 'error'

		notifyError({
			message: 'Payment failed',
			important: true,
		})

		redirectToReturnUrl(res.returnUrl)

	} catch (err) {
		status.value = 'error'

		notifyError({
			message: 'Could not verify payment',
			important: true,
		})

		clearPaymentParamsFromUrl()

		redirectToReturnUrl(undefined)
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
