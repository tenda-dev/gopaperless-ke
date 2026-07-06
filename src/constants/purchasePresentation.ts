import type { PaymentPresentation } from '@/payment/types'

export const CREDIT_PURCHASE_PRESENTATION: PaymentPresentation = {
	modalTitle: 'Buy Credits',
	summary: {
		title: 'Credits',
		subtitle: 'Credits are added to your account after payment.',
	},
}
