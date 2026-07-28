import type { PaymentPresentation } from '@/payment/types'

export const CREDIT_PURCHASE_PRESENTATION: PaymentPresentation = {
	modalTitle: 'Buy Certified Signatures',
	summary: {
		title: 'Certified Signatures',
		subtitle: 'Certified signatures can be used to sign your documents.',
	},
}

export const CERTIFICATE_PURCHASE_PRESENTATION: PaymentPresentation = {
	modalTitle: 'Set up your signing certificate',
	summary: {
		title: 'Signing certificate',
		subtitle: 'A one-time setup so your signatures are legally verifiable.',
	},
}
