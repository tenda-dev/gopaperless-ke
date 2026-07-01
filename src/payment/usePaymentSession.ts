import { ref, watch, type Ref } from 'vue'
import { resolvePhone } from '@/utils/phoneResolver'
import { normaliseRegion } from '@/utils/mobileMoney'
import type {
	HydratedPayment,
	MnoOption,
	MobilePaymentContext,
	PaymentMethod,
	PaymentPurpose,
	PaymentResponse,
	SelectedMno,
} from '@/payment'
import { showError, showInfo, showSuccess } from '@/services/toast'

/**
 * Payment session composable.
 *
 * Owns payment execution decisions previously inlined in
 * PaymentStep.vue: handleMobilePayment, handleCardPayment,
 * retryPayment, hydratePaymentState, handleCancelPaymentSession.
 *
 * Sits ABOVE usePayment (polling/state machine/persistence) and
 * BELOW PaymentStep.vue (UI events, template). Does not own
 * mnoDetection or phone resolution — both are injected dependencies
 * so this composable can mutate them without instantiating them.
 *
 * Consolidates the confidence→mnoDetection.state switch that was
 * previously duplicated three times (handleMobilePayment,
 * retryPayment, hydratePaymentState) into applyDetectionResult().
 *
 * See https://app.plane.so/gopaperless/browse/GPL-48/. Depends on (useMnoDetection,
 * usePhoneResolution).
 */
export function usePaymentSession({
	payment,             // usePayment() instance
	mnoDetection,        // reactive object from useMnoDetection()
	routing,             // usePaymentRouting() instance
	isValidSelectedProvider, // from useMnoDetection()
	resetMnoDetectionState,  // from useMnoDetection()
	phoneInput,
	resolution,
	detectedRegion,
	normalisedPhone,
	mobilePaymentContext,
	selectedMethod,
	isHydrating,
	props,
	user,
	emit,
	discardRecovery,
	selectMethod,
}: {
	payment: ReturnType<typeof import('@/payment').usePayment>
	mnoDetection: {
		state: 'idle' | 'detecting' | 'detected' | 'suggested' | 'requires-selection' | 'selected'
		confidence?: 'high' | 'ambiguous' | 'unknown' | null
		mno?: string | null
		country?: string | null
		reference: string | null
		options?: MnoOption[]
		selected: MnoOption | null
		showSelector: boolean
	}
	routing: { shouldStartPolling: (res: PaymentResponse | HydratedPayment) => boolean }
	isValidSelectedProvider: (selected?: SelectedMno | null) => boolean
	resetMnoDetectionState: () => void
	phoneInput: Ref<string>
	resolution: Ref<ReturnType<typeof resolvePhone> | null>
	detectedRegion: Ref<string>
	normalisedPhone: Ref<string>
	mobilePaymentContext: Ref<MobilePaymentContext | null>
	selectedMethod: Ref<PaymentMethod>
	isHydrating: Ref<boolean>
	props: {
		signUuid?: string
		signRequestId?: number
		productCode: string
		quantity: number
	}
	user: Ref<any>
	emit: (event:
		'payment-success' |
		'state-change' |
		'payment-runtime-invalid'
	) => void
	discardRecovery: () => void
	selectMethod: (m: PaymentMethod) => void
}) {

	const instructions = ref<string | null>(null)
	const processingStage = ref(0)

	let hasShownOfflineToast = false
	let processingTimers: ReturnType<typeof setTimeout>[] = []

   /**
	* Processing stage timer
	*/
	watch(() => payment.state.value, (state) => {
		processingTimers.forEach(clearTimeout)
		processingTimers = []

		processingStage.value = 0

		if (state !== 'processing') {
			return
		}

		processingTimers.push(
			setTimeout(() => (processingStage.value = 1), 2500),
			setTimeout(() => (processingStage.value = 2), 6000),
		)
    })


	/**
	* Network detection watcher
	*/
	watch(payment.isOffline, (offline) => {
		if (offline) {
			if (!hasShownOfflineToast) {
				showInfo(`Connection lost. We'll keep trying…`)
				hasShownOfflineToast = true
			}
		} else {
			if (payment.state.value === 'processing') {
				showSuccess('Back online. Resuming payment…')
				hasShownOfflineToast = false
			}
		}
	})

	/**
	 * Shared confidence → mnoDetection.state mapping.
	 *
	 * This is the block that was previously written three times
	 * (handleMobilePayment phase 1, retryPayment, hydratePaymentState)
	 * with minor variations. Single source of truth now.
	 */
	function applyDetectionResult(
		confidence: 'high' | 'ambiguous' | 'unknown' | null | undefined,
		isSelectedValid: boolean,
	) {
		if (isSelectedValid) {
			mnoDetection.state = 'selected'
			mnoDetection.showSelector = false
			return
		}

		switch (confidence) {
			case 'high':
				mnoDetection.state = 'detected'
				mnoDetection.showSelector = false
				break

			case 'ambiguous':
				mnoDetection.state = 'suggested'
				mnoDetection.showSelector = true
				break

			case 'unknown':
			default:
				mnoDetection.state = 'requires-selection'
				mnoDetection.showSelector = true
				break
		}
	}

	/**
	 * Apply a fresh PaymentResponse (from initiateOnly / retry)
	 * onto mnoDetection + mobilePaymentContext + detectedRegion.
	 */
	function applyPaymentResponse(response: PaymentResponse) {
		mobilePaymentContext.value = {
			displayAmount: response.displayAmount,
			displayAmountFormatted: response.displayAmountFormatted,
			displayCurrency: response.displayCurrency,
			phoneNumber: response.phoneNumber,
		}

		const resolvedRegion =
			response.phoneNumberRegion ??
			normaliseRegion(response.country)

		if (resolvedRegion) {
			detectedRegion.value = resolvedRegion
		}

		mnoDetection.reference = response.reference
		mnoDetection.confidence = response.confidence
		mnoDetection.mno = response.mno
		mnoDetection.country = response.country
		mnoDetection.options = response.options ?? []
	}

	function handleTerminalReset() {
		resetMnoDetectionState()
		payment.cancelActivePaymentSession()
	}

	function resetPaymentSession() {
		selectMethod('mobile')
		phoneInput.value = ''
		detectedRegion.value = 'KE'
		instructions.value = null
		mobilePaymentContext.value = null
		processingStage.value = 0
		hasShownOfflineToast = false

		resetMnoDetectionState()
	}

	/**
	 * MOBILE PAYMENT — Phase 1 (initiate) + Phase 2 (charge).
	 *
	 * Phase 1: no activeReference yet → create payment session,
	 * possibly auto-detect or require MNO selection.
	 *
	 * Phase 2: activeReference exists, user has confirmed MNO →
	 * charge the existing reference.
	 */
	async function initiateSession() {
		const resolvedPhone = resolution.value

		if (!resolvedPhone?.isValid) {
			showError('Enter a valid phone number and try again.')
			return
		}

		// Phase 1: no providerReference yet
		if (!payment.activeReference.value) {

			payment.state.value = 'initiating'

			const response = await payment.initiateOnly({
				provider: mnoDetection.confidence === 'high' && resolvedPhone.provider === 'daraja'
					? 'daraja'
					: 'dpo',
				paymentMethod: 'mobile',
				phoneNumber: resolvedPhone.e164,
				signRequestId: props.signRequestId,
				signUuid: props.signUuid,
				productCode: props.productCode,
				userId: user.value?.uid,
				userEmail: user.value?.emailAddress,
				purpose: payment.paymentPurpose.value!,
				quantity: props.quantity,
			})

			if (!response?.reference || !response?.flow) {
				payment.state.value = 'error'
				payment.error.value = 'Unable to start payment session. Please try again.'
				showError('Unable to start payment session.')

				setTimeout(() => {
					showInfo('Restarting payment session. Please try again.')
					payment.activeReference.value = null
					mnoDetection.reference = null
					resetPaymentSession()
					emit('payment-runtime-invalid')
				}, 2500)

				throw new Error('[PaymentSession] invalid initiation response')
			}

			payment.activeReference.value = response.reference
			payment.lockPaymentProvider(response.provider)

			if (response.paymentPurpose) {
				payment.lockPaymentPurpose(response.paymentPurpose)
			}

			applyPaymentResponse(response)

			if (routing.shouldStartPolling(response)) {
				instructions.value =
					response.instructions ??
					'Check your phone and complete the payment request.'

				payment.state.value = 'processing'

				payment.startPolling(response.reference, response.flow, (status) => {
					if (status === 'FAILED' || status === 'CANCELLED') handleTerminalReset()
				})

				return
			}

			applyDetectionResult(response.confidence, false)
			payment.state.value = 'idle'
			return
		}

		// Phase 2: charge existing reference
		const mnoToUse = mnoDetection.selected?.provider ?? mnoDetection.mno
		const countryToUse = mnoDetection.selected?.country ?? mnoDetection.country

		if (payment.activeReference.value && !payment.alreadyCharged) {
			const readyForCharge =
				mnoDetection.state === 'detected' ||
				(mnoDetection.state === 'selected' && !!mnoToUse)

			if (!readyForCharge) {
				showError('Please select your mobile network provider to continue.')
				return
			}
		}

		const phoneNumber =
			mobilePaymentContext.value?.phoneNumber ??
			normalisedPhone.value

		if (
			!payment.activeReference.value ||
			!phoneNumber ||
			!mnoToUse ||
			!countryToUse
		) {
			payment.state.value = 'error'
			payment.error.value = 'Unable to continue payment. Please restart the payment process.'
			showError('Payment session expired or became invalid. Restarting payment flow.')

			payment.activeReference.value = null
			mnoDetection.reference = null
			setTimeout(() => {
				resetPaymentSession()
				emit('payment-runtime-invalid')
			}, 2500)

			throw new Error('[PaymentSession] invalid charge payload')
		}

		const paymentPurpose = payment.paymentPurpose.value ?? 'sign_request'

		await payment.chargeExistingReference(
			{
				reference: payment.activeReference.value,
				phoneNumber,
				mno: mnoToUse,
				mnoCountry: countryToUse,
				paymentPurpose,
				signRequestId: props.signRequestId,
				signUuid: props.signUuid,
			},
			(status) => {
				if (status === 'FAILED' || status === 'CANCELLED') handleTerminalReset()
			}
		)
	}

	/**
	 * CARD PAYMENT — single initiation, redirect handled in usePayment.
	 */
	async function chargeSession() {
		await payment.startPayment(
			{
				provider: 'dpo',
				signRequestId: props.signRequestId,
				signUuid: props.signUuid,
				paymentMethod: 'card',
				userEmail: user.value?.emailAddress,
				userId: user.value?.uid,
				productCode: props.productCode,
				redirectUrl: payment.buildPaymentRedirectUrl(),
				quantity: props.quantity,
				purpose: payment.paymentPurpose.value ?? 'sign_request',
			},
			(status) => {
				if (status === 'FAILED' || status === 'CANCELLED') handleTerminalReset()
			}
		)
	}

	/**
	 * RETRY — reuses backend payment intent.
	 *
	 * ⚠️ TODO (PAY-HARD-002): once POST /payment/retry exists, replace
	 * the `payment.startPayment(...)` call below with
	 * `paymentDriver.retryPayment({ reference: payment.activeReference.value })`.
	 * The retry endpoint acts as a state-validation gate (terminal-state
	 * check, no provider re-query needed — polling in usePayment.startPolling
	 * already exhausts Daraja/DPO reconciliation before timeout fires) and
	 * delegates internally to startPayment with cloned intent. Response
	 * shape is unchanged, so everything below this comment stays the same.
	 */
	async function retrySession() {
		try {
			const payRes = await payment.startPayment(
				{
					signRequestId: props.signRequestId,
					signUuid: props.signUuid,
					paymentMethod: selectedMethod.value === 'card' ? 'card' : 'mobile',
					phoneNumber: normalisedPhone.value || undefined,
					userEmail: user.value?.emailAddress,
					userId: user.value?.uid,
					productCode: props.productCode,
					quantity: props.quantity,
					purpose: payment.paymentPurpose.value ?? 'sign_request',
				},
				(status) => {
					if (status === 'FAILED' || status === 'CANCELLED') handleTerminalReset()
				}
			)

			if (!payRes) return

			if (payRes.status === 'SUCCESS') {
				payment.state.value = 'success'
				return
			}

			if (payRes.status === 'FAILED') {
				payment.state.value = 'error'
				return
			}

			payment.activeReference.value = payRes.reference
			payment.lockPaymentProvider(payRes.provider)
			mnoDetection.reference = payRes.reference
			instructions.value = payRes.instructions ?? null
			selectedMethod.value = payRes.method
			payment.alreadyCharged.value = !!payRes.alreadyCharged

			if (payRes.paymentPurpose) {
				payment.lockPaymentPurpose(payRes.paymentPurpose)
			}

			if (payRes.flow === 'redirect') return

			if (payRes.flow === 'callback') {
				payment.retryCount.value += 1
				instructions.value = payRes.instructions ?? 'Check your phone and enter your M-Pesa PIN'
				return
			}

			if (payRes.flow === 'mobile_direct') {
				const selected = payRes.selected
				const isSelectedValid = !!selected && isValidSelectedProvider(selected)

				mnoDetection.confidence = payRes.confidence
				mnoDetection.mno = isSelectedValid ? selected.mno : payRes.mno
				mnoDetection.country = isSelectedValid ? selected.country : payRes.country

				if (payRes.alreadyCharged) {
					payment.retryCount.value += 1
					mnoDetection.state = isSelectedValid ? 'selected' : 'detected'
					return
				}

				if (!isSelectedValid && payRes.requiresProviderSelection) {
					mnoDetection.state = 'requires-selection'
					mnoDetection.showSelector = true
					mnoDetection.options = payRes.options ?? []
					return
				}

				applyDetectionResult(payRes.confidence, isSelectedValid)
				mnoDetection.options = payRes.options ?? []
			}

		} catch (err) {
			// handled in usePayment snackbars etc
		}
	}

	/**
	 * HYDRATE — restore runtime state after refresh/recovery.
	 */
	function applyHydratedPayment(hydratedPayment: HydratedPayment) {
		console.log('[PaymentSession] hydrating payment session', hydratedPayment)

		isHydrating.value = true

		if (hydratedPayment.method) {
			selectMethod(hydratedPayment.method)
		}

		payment.state.value = 'hydrating'

		if (hydratedPayment.phoneNumber) {
			phoneInput.value = hydratedPayment.phoneNumber

			const resolved = resolvePhone(hydratedPayment.phoneNumber)
			resolution.value = resolved

			if (hydratedPayment.phoneNumberRegion) {
				detectedRegion.value = hydratedPayment.phoneNumberRegion
				mnoDetection.country = hydratedPayment.phoneNumberCountry ?? null
			} else if (resolved.region) {
				detectedRegion.value = resolved.region
			}
		}

		mobilePaymentContext.value = {
			displayAmount: hydratedPayment.displayAmount,
			displayAmountFormatted: hydratedPayment.displayAmountFormatted,
			displayCurrency: hydratedPayment.displayCurrency,
		}

		if (hydratedPayment.instructions) {
			instructions.value = hydratedPayment.instructions ?? null
		}

		payment.activeReference.value = hydratedPayment.reference
		payment.lockPaymentProvider(hydratedPayment.provider)
		payment.alreadyCharged.value = !!hydratedPayment.alreadyCharged

		if (hydratedPayment.paymentPurpose) {
			payment.lockPaymentPurpose(hydratedPayment.paymentPurpose)
		}

		mnoDetection.reference = hydratedPayment.reference
		mnoDetection.confidence = hydratedPayment.confidence
		mnoDetection.mno = hydratedPayment.mno
		mnoDetection.country = hydratedPayment.country
		mnoDetection.options = hydratedPayment.options ?? []

		const selected = hydratedPayment.selected
		const isSelectedValid = !!(selected && isValidSelectedProvider(selected))

		mnoDetection.selected = isSelectedValid
			? { provider: selected!.mno, country: selected!.country }
			: null

		applyDetectionResult(hydratedPayment.confidence, isSelectedValid)

		// applyDetectionResult sets showSelector based on isSelectedValid/confidence,
		// but hydration additionally needs 'suggested' to show the selector —
		// preserved from the original hydratePaymentState behavior:
		mnoDetection.showSelector =
			mnoDetection.state === 'suggested' ||
			mnoDetection.state === 'requires-selection'

		const { method, redirectUrl } = hydratedPayment

		if (method === 'card' && redirectUrl) {
			discardRecovery()
			window.location.replace(redirectUrl)
			return
		}

		const shouldResumePolling = routing.shouldStartPolling(hydratedPayment)

		payment.state.value = shouldResumePolling ? 'processing' : 'idle'

		if (shouldResumePolling) {
			payment.startPolling(hydratedPayment.reference, hydratedPayment.flow, (status) => {
				if (status === 'FAILED' || status === 'CANCELLED') handleTerminalReset()
			})
		}

		requestAnimationFrame(() => {
			isHydrating.value = false
		})
	}

	/**
	 * CANCEL — frontend runtime reset only. Does not cancel
	 * provider-side payment or mutate backend payment state.
	 */
	async function cancelSession() {
		await payment.cancelActivePaymentSession()
		resetPaymentSession()
	}

	return {
		applyHydratedPayment,
		initiateSession,
		chargeSession,
		retrySession,
		cancelSession,
		resetPaymentSession,
		handleTerminalReset,
		instructions,
		processingStage,
	}
}
