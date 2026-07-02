import type { PaymentFlow, PaymentPurpose } from '@/payment/types'

/**
 * =========================================================
 * LOCAL STORAGE (SOFT RESUME ONLY)
 * =========================================================
 *
 * NOTE:
 * This is NOT the source of truth.
 * Backend is.
 *
 * Used only to resume polling after browser refresh.
 * Stale sessions (>10 min) are discarded automatically.
 */
const ACTIVE_PAYMENT_KEY = 'gopaperless_active_payment'
const MAX_RESUME_WINDOW = 10 * 60 * 1000 // 10 minutes

export type PersistedPaymentSession = {
	reference: string
	flow: PaymentFlow
	paymentPurpose: PaymentPurpose
	productCode?: string
	signRequestId?: number
	signUuid?: string
	timestamp: number // prefer timestamp to not confuse payment createdAt
}

export type PersistPaymentSessionPayload = Omit<PersistedPaymentSession, 'timestamp'>

function persistPaymentSession({ reference, flow, paymentPurpose, productCode, signRequestId, signUuid }: PersistPaymentSessionPayload) {
	const existing = getPersistedPaymentSession()

	const mergedProductCode = productCode
		?? (existing?.reference === reference ? existing.productCode : undefined)

	sessionStorage.setItem(
		ACTIVE_PAYMENT_KEY,
		JSON.stringify({
			reference,
			flow,
			paymentPurpose,
			productCode: mergedProductCode,
			signRequestId,
			signUuid,
			timestamp: Date.now(),
		})
	)
}

function clearPersistedPaymentSession() {
	sessionStorage.removeItem(ACTIVE_PAYMENT_KEY)
}

function getPersistedPaymentSession() {

	try {

		const raw = sessionStorage.getItem(
			ACTIVE_PAYMENT_KEY
		)

		if (!raw) return null

		const parsed = JSON.parse(raw)

		const {
			reference,
			paymentPurpose,
			timestamp,
		} = parsed

		if (
			!reference ||
			!paymentPurpose ||
			!timestamp
		) {
			clearPersistedPaymentSession()
			return null
		}

		const stillValid =
			Date.now() - timestamp < MAX_RESUME_WINDOW

		if (!stillValid) {
			clearPersistedPaymentSession()
			return null
		}

		return parsed as PersistedPaymentSession

	} catch {

		clearPersistedPaymentSession()
		return null
	}
}

export {
	persistPaymentSession,
	clearPersistedPaymentSession,
	getPersistedPaymentSession
}
