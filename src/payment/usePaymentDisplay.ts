import { computed, type Ref } from 'vue'
import type { MobilePaymentContext, PaymentPurpose } from '@/payment'
import type { Product } from '@/payment/product'

/**
 * Payment display composable.
 *
 * Pure derivations only — no side effects, no mutation,
 * no orchestration. Inputs in, formatted display values out.
 *
 * EXTRACTED VERBATIM from PaymentStep.vue (no logic changes)
 * See https://app.plane.so/gopaperless/browse/GPL-46/
 */
export function usePaymentDisplay({
	product,
	mobilePaymentContext,
	paymentPurpose,
	quantity,
}: {
	product: Ref<Product | null>
	mobilePaymentContext: Ref<MobilePaymentContext | null>
	paymentPurpose: Ref<PaymentPurpose | null>
	quantity: number
}) {

	function formatAmount(amount: number, currency: string) {
		return `${currency} ${(amount / 100).toFixed(2)}`
	}

	const estimatedAmount = computed(() =>
		product.value && quantity
			? product.value.amount * quantity
			: 0
	)

	const displayAmount = computed(() => {
		if (!product.value) {
			return null
		}

		// FX/payment-aware amount
		if (
			mobilePaymentContext.value?.displayAmountFormatted &&
			mobilePaymentContext.value?.displayCurrency
		) {
			return {
				primary:
					mobilePaymentContext.value.displayAmountFormatted,

				currency:
					mobilePaymentContext.value.displayCurrency,

				secondary:
					formatAmount(
						product.value.amount,
						product.value.currency
					),

				hasFx: (
					mobilePaymentContext.value.displayCurrency !==
					product.value.currency
				),
			}
		}

		// fallback before payment creation
		return {
			primary: formatAmount(
				estimatedAmount.value,
				product.value.currency
			),

			currency: product.value.currency,

			secondary: null,

			hasFx: false,
		}
	})

	const paymentDisplayLabel = computed(() => {
		if (displayAmount.value?.primary) {
			return displayAmount.value.primary
		}

		if (product.value) {
			return formatAmount(
				product.value.amount,
				product.value.currency
			)
		}

		return null
	})

	const paymentSummaryTitle = computed(() => {
		switch (paymentPurpose.value) {
			case 'credit_purchase':
				return quantity === 1
						? '1 Signing Credit'
						: `${quantity} Signing Credits`

			case 'sign_request':
				return 'Signature Payment'

			default:
				return 'Payment'
		}
	})

	const paymentSummarySubtitle = computed(() => {
		switch (paymentPurpose.value) {
			case 'credit_purchase':
				return 'One-time purchase'

			case 'sign_request':
				return 'One-time signing fee'

			default:
				return 'Secure payment'
		}
	})

	return {
		formatAmount,
		estimatedAmount,
		displayAmount,
		paymentDisplayLabel,
		paymentSummaryTitle,
		paymentSummarySubtitle,
	}
}
