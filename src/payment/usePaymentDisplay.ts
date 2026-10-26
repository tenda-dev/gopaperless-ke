import { computed, type Ref } from 'vue'
import type { MobilePaymentContext, PaymentPurpose } from '@/payment/types'
import type { Product } from '@/payment/product'

interface PaymentDisplayOptions {
	product: Ref<Product | null>
	quantity: Ref<number> | number
	mobilePaymentContext: Ref<MobilePaymentContext | null>
	paymentPurpose: Ref<PaymentPurpose>
}

function formatAmount(amount: number, currency: string): string {
	return `${currency} ${(amount / 100).toFixed(2)}`
}

export function usePaymentDisplay(options: PaymentDisplayOptions) {
	const quantity = computed(() =>
		typeof options.quantity === 'number'
			? options.quantity
			: options.quantity.value,
	)

	const estimatedAmount = computed(() =>
		options.product.value && quantity.value
			? options.product.value.amount * quantity.value
			: 0,
	)

	const displayAmount = computed(() => {
		const product = options.product.value

		if (!product) {
			return null
		}

		const context = options.mobilePaymentContext.value

		if (
			context?.displayAmountFormatted &&
			context?.displayCurrency
		) {
			return {
				primary: context.displayAmountFormatted,
				currency: context.displayCurrency,
				secondary: formatAmount(product.amount, product.currency),
				hasFx: context.displayCurrency !== product.currency,
			}
		}

		return {
			primary: formatAmount(estimatedAmount.value, product.currency),
			currency: product.currency,
			secondary: null,
			hasFx: false,
		}
	})

	const paymentDisplayLabel = computed(() => {
		if (displayAmount.value?.primary) {
			return displayAmount.value.primary
		}

		const product = options.product.value

		if (product) {
			return formatAmount(product.amount, product.currency)
		}

		return null
	})

	const paymentSummaryTitle = computed(() => {
		switch (options.paymentPurpose.value) {
			case 'credit_purchase':
				return quantity.value === 1
					? '1 Signing Credit'
					: `${quantity.value} Signing Credits`

			case 'sign_request':
				return 'Signature Payment'

			default:
				return 'Payment'
		}
	})

	const paymentSummarySubtitle = computed(() => {
		switch (options.paymentPurpose.value) {
			case 'credit_purchase':
				return 'One-time purchase'

			case 'sign_request':
				return 'One-time signing fee'

			default:
				return 'Secure payment'
		}
	})

	return {
		estimatedAmount,
		displayAmount,
		paymentDisplayLabel,
		paymentSummaryTitle,
		paymentSummarySubtitle,
	}
}
