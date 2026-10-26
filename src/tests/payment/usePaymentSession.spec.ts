/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Focused parity tests for the restored payment composables.
 * Expectations are pinned to the behaviour of the live
 * src/components/Payments/PaymentStep.vue (the behavioural source of
 * truth); they are NOT derived from the composable's own assumptions.
 * The composables remain inert/unwired — these tests exercise them in
 * isolation with a mocked usePayment and the real useMnoDetection /
 * usePaymentRouting.
 */
import { ref, computed } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/services/toast', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@/composables/useNetworkState', () => ({
	useNetworkState: () => ({ isOffline: ref(false) }),
}))

import { usePaymentSession } from '@/payment/usePaymentSession'
import { useMnoDetection } from '@/payment/useMnoDetection'
import { usePaymentRouting } from '@/payment/usePaymentRouting'

function makePayment(overrides: Record<string, any> = {}) {
	const state = ref('idle')
	const paymentPurpose = ref(overrides.purpose ?? 'sign_request')
	return {
		state,
		error: ref<string | null>(null),
		isOffline: ref(false),
		activeReference: ref<string | null>(null),
		alreadyCharged: ref(false),
		provider: ref<string | null>(null),
		providerLocked: ref(false),
		retryCount: ref(0),
		paymentPurpose,
		isProcessing: computed(() =>
			['hydrating', 'processing', 'initiating', 'requesting'].includes(state.value),
		),
		initiateOnly: vi.fn(),
		chargeExistingReference: vi.fn().mockResolvedValue(undefined),
		startPayment: vi.fn(),
		startPolling: vi.fn(),
		lockPaymentProvider: vi.fn(),
		lockPaymentPurpose: vi.fn(),
		resetProviderLock: vi.fn(),
		cancelActivePaymentSession: vi.fn().mockResolvedValue(undefined),
		buildPaymentRedirectUrl: vi.fn(() => 'https://redirect'),
		buildPaymentReturnUrl: vi.fn(() => 'https://return'),
		fetchMobileOptions: vi.fn().mockResolvedValue({ options: [] }),
	} as any
}

function makeSession(payment: any, ctx: Record<string, any> = {}) {
	const phoneInput = ref('')
	const resolution = ref(
		ctx.resolution ?? { isValid: true, e164: '+254700000000', provider: 'dpo', region: 'KE' },
	)
	const detectedRegion = ref('KE')
	const normalisedPhone = ref(resolution.value?.e164 ?? '')
	const mobilePaymentContext = ref<any>(null)
	const selectedMethod = ref(ctx.selectedMethod ?? 'mobile')
	const isHydrating = ref(false)
	const resolvedSignUuid = ref<string | null>(null)
	const isDaraja = ref<boolean>(ctx.isDaraja ?? false)

	const isProcessing = payment.isProcessing
	const canEditProvider = computed(
		() => !!payment.activeReference.value && !payment.alreadyCharged.value && payment.state.value === 'idle',
	)

	const mno = useMnoDetection({
		isDaraja,
		resolution: resolution as any,
		fetchMobileOptions: payment.fetchMobileOptions,
		isProcessing,
		canEditProvider,
	})
	const routing = usePaymentRouting()

	const selectMethod = (m: any) => {
		if (isProcessing.value) return
		selectedMethod.value = m
	}

	const sessionContext = {
		signUuid: ctx.signUuid,
		signRequestId: ctx.signRequestId ?? 123,
		sponsorshipWorkflowFileUuid: ctx.sponsorshipWorkflowFileUuid ?? 'wf-1',
		productCode: 'CREDIT',
		quantity: 1,
		purpose: ctx.purpose ?? payment.paymentPurpose.value,
	}

	const session = usePaymentSession({
		payment,
		mnoDetection: mno.mnoDetection,
		routing: routing as any,
		applyMnoDetection: mno.applyMnoDetection,
		resetMnoDetectionState: mno.resetMnoDetectionState,
		phoneInput,
		resolution: resolution as any,
		detectedRegion,
		normalisedPhone,
		mobilePaymentContext,
		selectedMethod: selectedMethod as any,
		isHydrating,
		sessionContext: sessionContext as any,
		user: ref({ uid: 'u1', emailAddress: 'a@b.com' }) as any,
		onRuntimeInvalid: vi.fn(),
		discardRecovery: vi.fn(),
		selectMethod,
		isDaraja,
		resolvedSignUuid,
		isValidSelectedProvider: mno.isValidSelectedProvider,
		paymentDisplayLabel: computed(() => 'KES 10'),
	})

	return { session, payment, selectedMethod, mnoDetection: mno.mnoDetection, resolvedSignUuid, mobilePaymentContext }
}

beforeEach(() => {
	if (!globalThis.requestAnimationFrame) {
		// happy-dom fallback
		;(globalThis as any).requestAnimationFrame = (cb: any) => setTimeout(cb, 0)
	}
	vi.clearAllMocks()
})

describe('usePaymentSession parity', () => {
	it('D3/D2: phase-1 mobile_direct ambiguous sets state but NOT showSelector, no charge', async () => {
		const payment = makePayment()
		const { session, mnoDetection } = makeSession(payment)
		payment.initiateOnly.mockResolvedValue({
			reference: 'ref1', flow: 'mobile_direct', provider: 'dpo',
			confidence: 'ambiguous', mno: null, country: 'KE', options: [], paymentPurpose: 'sign_request',
		})

		await session.initiateSession()

		expect(mnoDetection.state).toBe('suggested')
		expect(mnoDetection.showSelector).toBe(false) // live component does NOT auto-open here
		expect(payment.chargeExistingReference).not.toHaveBeenCalled()
		expect(payment.state.value).toBe('idle')
	})

	it('D3: provider hint uses injected isDaraja (backend-locked), not resolvedPhone.provider', async () => {
		const payment = makePayment()
		const { session } = makeSession(payment, { isDaraja: true })
		payment.initiateOnly.mockResolvedValue({
			reference: 'ref1', flow: 'callback', provider: 'daraja', confidence: 'high', options: [],
		})
		await session.initiateSession()
		expect(payment.initiateOnly.mock.calls[0][0].provider).toBe('daraja')
	})

	it('D1/D2: autoCharge (credit purchase) charges existing reference exactly once', async () => {
		const payment = makePayment({ purpose: 'credit_purchase' })
		const { session, mnoDetection } = makeSession(payment, { purpose: 'credit_purchase' })
		payment.initiateOnly.mockResolvedValue({
			reference: 'ref1', flow: 'mobile_direct', provider: 'dpo', confidence: 'high',
			mno: 'mpesa', country: 'KE', options: [], phoneNumber: '+254700000000',
			autoCharge: { enabled: true, providerMnoKey: 'MPESA_KE', country: 'KE' },
		})

		await session.initiateSession()

		expect(payment.chargeExistingReference).toHaveBeenCalledTimes(1)
		const arg = payment.chargeExistingReference.mock.calls[0][0]
		expect(arg.mno).toBe('MPESA_KE')
		expect(arg.mnoCountry).toBe('KE')
		expect(mnoDetection.state).toBe('selected')
	})

	it('D5/D10: sign_request autoCharge is blocked when signUuid is missing (no charge)', async () => {
		const payment = makePayment({ purpose: 'sign_request' })
		const { session, mnoDetection } = makeSession(payment, { purpose: 'sign_request', signUuid: undefined })
		payment.initiateOnly.mockResolvedValue({
			reference: 'ref1', flow: 'mobile_direct', provider: 'dpo', confidence: 'high',
			mno: 'mpesa', country: 'KE', options: [], phoneNumber: '+254700000000',
			autoCharge: { enabled: true, providerMnoKey: 'MPESA_KE', country: 'KE' },
		})

		await session.initiateSession()

		expect(payment.chargeExistingReference).not.toHaveBeenCalled()
		expect(mnoDetection.state).toBe('detected') // confidence switch ran; guard returned before idle
	})

	it('D9: backend-resolved signUuid is cached into resolvedSignUuid', async () => {
		const payment = makePayment()
		const { session, resolvedSignUuid } = makeSession(payment)
		payment.initiateOnly.mockResolvedValue({
			reference: 'ref1', flow: 'mobile_direct', provider: 'dpo', confidence: 'ambiguous',
			options: [], signUuid: 'resolved-uuid',
		})
		await session.initiateSession()
		expect(resolvedSignUuid.value).toBe('resolved-uuid')
	})

	it('D5: card chargeSession payload carries returnUrl + sponsorshipWorkflowFileUuid', async () => {
		const payment = makePayment()
		const { session } = makeSession(payment, { selectedMethod: 'card' })
		await session.chargeSession()
		const arg = payment.startPayment.mock.calls[0][0]
		expect(arg.returnUrl).toBe('https://return')
		expect(arg.sponsorshipWorkflowFileUuid).toBe('wf-1')
		expect(arg.redirectUrl).toBe('https://redirect')
	})

	it('D6: card retry carries redirectUrl/returnUrl/sponsorship; mobile retry carries none', async () => {
		const cardPayment = makePayment()
		const card = makeSession(cardPayment, { selectedMethod: 'card' })
		cardPayment.startPayment.mockResolvedValue(null)
		await card.session.retrySession()
		const cardArg = cardPayment.startPayment.mock.calls[0][0]
		expect(cardArg.redirectUrl).toBe('https://redirect')
		expect(cardArg.returnUrl).toBe('https://return')
		expect(cardArg.sponsorshipWorkflowFileUuid).toBe('wf-1')

		const mobPayment = makePayment()
		const mob = makeSession(mobPayment, { selectedMethod: 'mobile' })
		mobPayment.startPayment.mockResolvedValue(null)
		await mob.session.retrySession()
		const mobArg = mobPayment.startPayment.mock.calls[0][0]
		expect(mobArg.redirectUrl).toBeNull()
		expect(mobArg.returnUrl).toBeNull()
		expect(mobArg.sponsorshipWorkflowFileUuid).toBeUndefined()
	})

	it('D8: retry alreadyCharged + ambiguous keeps detected (not suggested), selected untouched', async () => {
		const payment = makePayment()
		const { session, mnoDetection } = makeSession(payment, { selectedMethod: 'mobile' })
		payment.startPayment.mockResolvedValue({
			reference: 'r2', provider: 'dpo', method: 'mobile', flow: 'mobile_direct',
			status: 'PENDING', alreadyCharged: true, confidence: 'ambiguous',
			mno: 'mpesa', country: 'KE', selected: null, options: [],
		})
		await session.retrySession()
		expect(mnoDetection.state).toBe('detected')
		expect(mnoDetection.selected).toBeNull()
	})

	it('D1: hydrating a non-redirect card session restores selectedMethod = card', async () => {
		const payment = makePayment()
		const { session, selectedMethod } = makeSession(payment, { selectedMethod: 'mobile' })
		session.applyHydratedPayment({
			method: 'card', reference: 'r1', provider: 'dpo', flow: 'mobile_direct',
			confidence: 'high', options: [],
		} as any)
		expect(selectedMethod.value).toBe('card')
	})

	it('D10: handlePay forces error state when the handler throws', async () => {
		const payment = makePayment()
		const { session } = makeSession(payment, { selectedMethod: 'mobile' })
		payment.initiateOnly.mockRejectedValue(new Error('boom'))
		// setup.js makes console.error throw; silence it so we can assert the
		// post-catch state (the catch sets 'error' BEFORE it logs).
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		await session.handlePay()
		errSpy.mockRestore()
		expect(payment.state.value).toBe('error')
	})
})
