import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import { resolvePhone } from '@/utils/phoneResolver'
import { normaliseRegion } from '@/utils/mobileMoney'
import type {
	HydratedPayment,
	MobilePaymentContext,
	PaymentMethod,
	PaymentResponse,
	PaymentSessionContext,
	PaymentTerminalStatus,
} from '@/payment'
import { showError, showInfo, showSuccess } from '@/services/toast'
import type { UserContext } from '@/store/userContext'
import type { MnoDetectionPayload, MnoDetectionState } from './useMnoDetection'

//
// Types
//

export type PaymentSession = {
	payment: ReturnType<typeof import('@/payment').usePayment>
	mnoDetection: MnoDetectionState
	routing: { shouldStartPolling: (res: PaymentResponse | HydratedPayment) => boolean }
	applyMnoDetection: (response: MnoDetectionPayload) => boolean
	resetMnoDetectionState: () => void
	phoneInput: Ref<string>
	resolution: Ref<ReturnType<typeof resolvePhone> | null>
	detectedRegion: Ref<string>
	normalisedPhone: Ref<string>
	mobilePaymentContext: Ref<MobilePaymentContext | null>
	selectedMethod: Ref<PaymentMethod>
	isHydrating: Ref<boolean>
	sessionContext: PaymentSessionContext
	user: Ref<UserContext | null>
	onRuntimeInvalid: () => void
	discardRecovery: () => void
	selectMethod: (m: PaymentMethod) => void
	paymentDisplayLabel: ComputedRef<string | null>
}

/**
 * usePaymentSession
 *
 * Owns payment execution decisions previously inlined in
 * PaymentStep.vue:
 *   - handleMobilePayment  → initiateSession()
 *   - handleCardPayment    → chargeSession()
 *   - retryPayment         → retrySession()
 *   - hydratePaymentState  → applyHydratedPayment()
 *   - handleCancelPaymentSession → cancelSession()
 *
 * Layer position:
 *   PaymentStep.vue (UI events, template)
 *     └── usePaymentSession    ← HERE (execution decisions)
 *           └── usePayment     (polling, state machine, persistence)
 *
 * Does NOT own mnoDetection or phone resolution — both are
 * injected so this composable can mutate them without
 * instantiating them, keeping composition unidirectional.
 *
 * Key consolidation:
 * The confidence→mnoDetection.state switch was previously
 * duplicated three times across handleMobilePayment, retryPayment,
 * and hydratePaymentState. It now lives once in applyDetectionResult().
 *
 * See https://app.plane.so/gopaperless/browse/GPL-48/.
 */
export function usePaymentSession({
	payment,
	mnoDetection,
	routing,
	applyMnoDetection,
	resetMnoDetectionState,
	phoneInput,
	resolution,
	detectedRegion,
	normalisedPhone,
	mobilePaymentContext,
	selectedMethod,
	isHydrating,
	sessionContext,
	user,
	onRuntimeInvalid,
	discardRecovery,
	selectMethod,
	paymentDisplayLabel,
}: PaymentSession) {

	//
	// Local state
	//

	const instructions = ref<string | null>(null)

	/**
	 * Three-stage processing indicator (0 → 1 → 2).
	 * Drives the dot animation in the template while
	 * the user is waiting for provider confirmation.
	 */
	const processingStage = ref(0)

	/**
	 * Prevents the "connection lost" toast from firing on
	 * every polling tick while offline. Cleared on reconnect
	 * so the user sees the recovery toast exactly once.
	 */
	let hasShownOfflineToast = false

	/**
	 * Tracks active processing stage timers so they can be
	 * cancelled on state transitions. The original code did not
	 * clear these, which could stack callbacks on rapid transitions.
	 */
	let processingTimers: ReturnType<typeof setTimeout>[] = []

	//
	// Derived state
	//

	/**
	 * Primary CTA label.
	 *
	 * Resolves through payment state first, then MNO detection
	 * state for the idle/ready cases. Order matters — payment
	 * state always takes precedence over detection state.
	 */
	const buttonLabel = computed(() => {
		if (payment.isOffline.value) return 'Waiting for connection…'

		if (mnoDetection.state === 'detecting') {
			return 'Detecting your network…'
		}

		switch (payment.state.value) {
			case 'timeout':   return 'Retry payment'
			case 'error':     return 'Try again'
			case 'cancelled': return 'Try again'
			case 'processing': return 'Approve on your phone…'
			case 'requesting': return 'Sending payment request...'
			case 'initiating': return 'Starting payment…'

			default:
				if (selectedMethod.value === 'card') {
					return 'Continue to card payment'
				}

				if (
					mnoDetection.state === 'detected' ||
					mnoDetection.state === 'selected'
				) {
					return paymentDisplayLabel.value
						? `Confirm — pay ${paymentDisplayLabel.value}`
						: 'Confirm & pay'
				}

				if (
					mnoDetection.state === 'suggested' ||
					mnoDetection.state === 'requires-selection'
				) {
					return mnoDetection.selected
						? `Confirm — pay ${paymentDisplayLabel.value ?? ''}`
						: 'Select a provider to continue'
				}

				return 'Continue'
		}
	})

	/**
	 * Contextual processing message shown above the CTA.
	 *
	 * Priority: offline → instructions (backend-supplied) →
	 * processing stage → provider-specific default.
	 */
	const paymentMessage = computed(() => {
		if (payment.isOffline.value) {
			return `Connection lost. We'll resume automatically…`
		}

		if (instructions.value) return instructions.value

		if (processingStage.value === 2) return 'Almost there… waiting for confirmation'
		if (processingStage.value === 1) return 'Still working… this can take a few seconds'

		return resolution.value?.provider === 'daraja'
			? 'Check your phone and enter your M-Pesa PIN'
			: 'Approve payment on your phone'
	})

	//
	// Watchers
	//

	/**
	 * Advance processingStage (0 → 1 → 2) while the user
	 * is waiting for provider confirmation.
	 *
	 * Timers are cleared on every state change to prevent
	 * stacked callbacks from rapid transitions.
	 */
	watch(() => payment.state.value, (state) => {
		processingTimers.forEach(clearTimeout)
		processingTimers = []
		processingStage.value = 0

		if (state !== 'processing') return

		processingTimers.push(
			setTimeout(() => (processingStage.value = 1), 2500),
			setTimeout(() => (processingStage.value = 2), 6000),
		)
	})

	/**
	 * Surface offline/online transitions as toasts.
	 *
	 * Deduplicates the offline toast — once shown, suppressed
	 * until the user reconnects and we confirm they're back
	 * in an active payment session.
	 */
	watch(payment.isOffline, (offline) => {
		if (offline) {
			if (!hasShownOfflineToast) {
				showInfo(`Connection lost. We'll keep trying…`)
				hasShownOfflineToast = true
			}
		} else if (payment.state.value === 'processing') {
			showSuccess('Back online. Resuming payment…')
			hasShownOfflineToast = false
		}
	})

	//
	// Internal helpers
	//

	/**
	 * Shared confidence → mnoDetection.state mapping.
	 *
	 * Previously written three times (handleMobilePayment,
	 * retryPayment, hydratePaymentState). Single source of
	 * truth now — changes to routing confidence rules happen here.
	 *
	 * Also sets showSelector so callers don't have to.
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
	 * Apply a fresh initiation response onto mnoDetection,
	 * mobilePaymentContext, and detectedRegion.
	 *
	 * Called after initiateOnly() resolves. Separated from
	 * applyHydratedPayment() because initiation responses have
	 * a narrower shape — no selected MNO, no resume-polling logic.
	 */
	function applyPaymentResponse(response: PaymentResponse) {
		mobilePaymentContext.value = {
			displayAmount:          response.displayAmount,
			displayAmountFormatted: response.displayAmountFormatted,
			displayCurrency:        response.displayCurrency,
			phoneNumber:            response.phoneNumber,
		}

		// Prefer backend-resolved region; fall back to FE normalisation
		const resolvedRegion =
			response.phoneNumberRegion ??
			normaliseRegion(response.country)

		if (resolvedRegion) {
			detectedRegion.value = resolvedRegion
		}

		mnoDetection.reference = response.reference
		mnoDetection.confidence = response.confidence
		mnoDetection.mno        = response.mno
		mnoDetection.country    = response.country
		mnoDetection.options    = response.options ?? []
	}

	/**
	 * Terminal reset — called when polling resolves to FAILED or CANCELLED.
	 *
	 * Resets MNO detection and clears the active provider reference
	 * so the user can retry. Does NOT cancel the full session —
	 * cancelSession() exists for explicit user-initiated cancellation.
	 *
	 * IMPORTANT: this is intentionally NOT cancelActivePaymentSession().
	 * Terminal reset fires mid-flow while usePayment is still wrapping
	 * up its own state transitions. Calling cancelActivePaymentSession()
	 * here would fight with the polling state machine (it calls stopPolling,
	 * clears retryCount, resets purpose, sets state = 'idle').
	 */
	function handleTerminalReset() {
		resetMnoDetectionState()
		payment.activeReference.value = null
		payment.alreadyCharged.value  = false
		payment.resetProviderLock()
	}

	/**
	 * Reusable terminal callback passed to all polling entry points.
	 * Defined once so the reference is stable across initiateSession,
	 * retrySession, and applyHydratedPayment.
	 */
	const onTerminal = (status: PaymentTerminalStatus) => {
		if (status === 'FAILED' || status === 'CANCELLED') {
			handleTerminalReset()
		}
	}

	/**
	 * Full session reset — used by cancelSession() and runtime-invalid
	 * recovery paths. Restores all local state to initial values.
	 */
	function resetPaymentSession() {
		selectMethod('mobile')
		phoneInput.value = ''
		detectedRegion.value = 'KE'
		instructions.value = null
		mobilePaymentContext.value = null
		processingStage.value = 0
		processingTimers = []
		hasShownOfflineToast = false
		resetMnoDetectionState()
	}

	/**
	 * Deferred 2.5s runtime-invalid recovery sequence.
	 *
	 * Used when the initiation or charge response is structurally
	 * invalid. Shows a toast, resets state, and notifies the parent
	 * so it can take action (e.g. close modal, re-fetch product).
	 */
	function scheduleRuntimeInvalidRecovery() {
		setTimeout(() => {
			showInfo('Restarting payment session. Please try again.')
			payment.activeReference.value = null
			mnoDetection.reference        = null
			resetPaymentSession()
			onRuntimeInvalid()
		}, 2500)
	}

	//
	// Public API
	//

	/**
	 * Primary CTA dispatcher.
	 *
	 * Routes to retry if we're in a terminal state with no
	 * resumable session reference, otherwise routes to the
	 * appropriate payment method handler.
	 */
	async function handlePay() {
		if (
			payment.state.value === 'processing' ||
			payment.state.value === 'hydrating'
		) return

		const canContinueExistingFlow =
			!!mnoDetection.reference &&
			(
				mnoDetection.state === 'detected'           ||
				mnoDetection.state === 'selected'           ||
				mnoDetection.state === 'requires-selection' ||
				mnoDetection.state === 'suggested'
			)

		if (
			(payment.state.value === 'timeout' || payment.state.value === 'error') &&
			!canContinueExistingFlow
		) {
			return retrySession()
		}

		try {
			if (selectedMethod.value === 'mobile') {
				await initiateSession()
			} else if (selectedMethod.value === 'card') {
				await chargeSession()
			} else {
				showError('Invalid payment method selected')
			}
		} catch (err) {
			console.error('[PaymentSession] handlePay failed', err)
		}
	}

	/**
	 * MOBILE PAYMENT — Phase 1 (initiate) + Phase 2 (charge).
	 *
	 * Phase 1 (no activeReference):
	 *   Calls initiateOnly() to create a backend payment session.
	 *   If the backend auto-executes (Daraja callback flow), starts
	 *   polling immediately. Otherwise applies detection result and
	 *   waits for the user to confirm their MNO.
	 *
	 * Phase 2 (activeReference exists, user confirmed MNO):
	 *   Charges the existing reference via chargeExistingReference().
	 *   This is the DPO mobile_direct path — STK push is Daraja-only
	 *   and handled in Phase 1 via shouldStartPolling.
	 */
	async function initiateSession() {
		const resolvedPhone = resolution.value

		if (!resolvedPhone?.isValid) {
			showError('Enter a valid phone number and try again.')
			return
		}

		if (!user?.value) return

		const { signRequestId, signUuid, productCode, quantity } = sessionContext

		// Phase 1
		if (!payment.activeReference.value) {

			payment.state.value = 'initiating'

			const response = await payment.initiateOnly({
				/**
				 * Provider hint only — backend is the routing authority.
				 * We pass 'daraja' when the resolved phone is a KE Safaricom
				 * number so the backend can skip DPO provider detection.
				 */
				provider:      resolvedPhone.provider === 'daraja' ? 'daraja' : 'dpo',
				paymentMethod: 'mobile',
				phoneNumber:   resolvedPhone.e164,
				signRequestId,
				signUuid,
				productCode,
				userId:        user.value.uid,
				userEmail:     user.value.emailAddress,
				purpose:       payment.paymentPurpose.value!,
				quantity,
			})

			/**
			 * Defensive guard: a response without reference or flow
			 * is unrecoverable — we cannot poll or resume without them.
			 */
			if (!response?.reference || !response?.flow) {
				payment.state.value  = 'error'
				payment.error.value  = 'Unable to start payment session. Please try again.'
				showError('Unable to start payment session.')
				scheduleRuntimeInvalidRecovery()
				throw new Error('[PaymentSession] invalid initiation response')
			}

			payment.activeReference.value = response.reference
			payment.lockPaymentProvider(response.provider)

			if (response.paymentPurpose) {
				payment.lockPaymentPurpose(response.paymentPurpose)
			}

			applyPaymentResponse(response)

			/**
			 * Daraja (callback) and any flow where the backend already
			 * dispatched provider execution — begin polling immediately.
			 */
			if (routing.shouldStartPolling(response)) {
				instructions.value  =
					response.instructions ??
					'Check your phone and complete the payment request.'

				payment.state.value = 'processing'
				payment.startPolling(response.reference, response.flow, onTerminal)
				return
			}

			/**
			 * DPO mobile_direct — backend created the reference but
			 * has not charged yet. Apply detection result and wait for
			 * the user to confirm their MNO before Phase 2.
			 */
			applyDetectionResult(response.confidence, false)
			payment.state.value = 'idle'
			return
		}

		// Phase 2
		const mnoToUse     = mnoDetection.selected?.provider ?? mnoDetection.mno
		const countryToUse = mnoDetection.selected?.country  ?? mnoDetection.country

		/**
		 * Require explicit selection before charging when the provider
		 * hasn't been confirmed yet. 'detected' (high-confidence) passes
		 * through without user interaction; 'selected' requires a chosen MNO.
		 */
		if (payment.activeReference.value && !payment.alreadyCharged) {
			const readyForCharge =
				mnoDetection.state === 'detected' ||
				(mnoDetection.state === 'selected' && !!mnoToUse)

			if (!readyForCharge) {
				showError('Please select your mobile network provider to continue.')
				return
			}
		}

		/**
		 * Prefer backend-confirmed phone (may differ from user input
		 * after normalisation on the backend side). Fall back to the
		 * FE-normalised value if the backend didn't return one.
		 */
		const phoneNumber =
			mobilePaymentContext.value?.phoneNumber ??
			normalisedPhone.value

		/**
		 * Final defensive guard before the charge call.
		 * Any missing field here means the session is irrecoverable.
		 */
		if (
			!payment.activeReference.value ||
			!phoneNumber                   ||
			!mnoToUse                      ||
			!countryToUse
		) {
			payment.state.value = 'error'
			payment.error.value = 'Unable to continue payment. Please restart the payment process.'
			showError('Payment session expired or became invalid. Restarting payment flow.')
			payment.activeReference.value = null
			mnoDetection.reference        = null
			scheduleRuntimeInvalidRecovery()
			throw new Error('[PaymentSession] invalid charge payload')
		}

		await payment.chargeExistingReference(
			{
				reference:     payment.activeReference.value,
				phoneNumber,
				mno:           mnoToUse,
				mnoCountry:    countryToUse,
				paymentPurpose: payment.paymentPurpose.value ?? 'sign_request',
				signRequestId,
				signUuid,
			},
			onTerminal
		)
	}

	/**
	 * CARD PAYMENT
	 *
	 * Initiates via startPayment() which redirects to the DPO
	 * card form. State management after redirect is handled by
	 * usePaymentReturnVerification on the return route.
	 */
	async function chargeSession() {
		if (!user?.value) return

		await payment.startPayment(
			{
				provider:      'dpo',
				paymentMethod: 'card',
				userEmail:     user.value.emailAddress,
				userId:        user.value.uid,
				productCode:   sessionContext.productCode,
				redirectUrl:   payment.buildPaymentRedirectUrl(),
				quantity:      sessionContext.quantity,
				purpose:       payment.paymentPurpose.value ?? 'sign_request',
				signRequestId: sessionContext.signRequestId,
				signUuid:      sessionContext.signUuid,
			},
			onTerminal
		)
	}

	/**
	 * RETRY
	 *
	 * Re-initiates the payment intent after a terminal state
	 * (timeout, error, cancelled). Clones the original intent
	 * so the user doesn't have to re-enter their details.
	 *
	 * ⚠️  TODO (GPL-44):
	 * Replace payment.startPayment() here with a dedicated
	 * paymentDriver.retryPayment({ reference }) call once the
	 * POST /payment/retry endpoint exists. That endpoint acts as
	 * a state-validation gate (verifies terminal state, guards
	 * against re-initiating a PENDING + alreadyCharged session)
	 * and delegates internally to startPayment with cloned intent.
	 * Response shape is identical — nothing below changes.
	 */
	async function retrySession() {
		if (!user?.value) return

		const { signRequestId, signUuid, productCode, quantity } = sessionContext

		try {
			const response = await payment.startPayment(
				{
					signRequestId,
					signUuid,
					paymentMethod: selectedMethod.value === 'card' ? 'card' : 'mobile',
					phoneNumber:   normalisedPhone.value || undefined,
					userEmail:     user.value.emailAddress,
					userId:        user.value.uid,
					productCode,
					quantity,
					purpose:       payment.paymentPurpose.value ?? 'sign_request',
				},
				onTerminal,
			)

			if (!response) return

			// Terminal statuses are handled by usePayment internally —
			// we only need to sync local state here
			if (response.status === 'SUCCESS') { payment.state.value = 'success'; return }
			if (response.status === 'FAILED')  { payment.state.value = 'error';   return }

			// Restore session references from the new response
			payment.activeReference.value  = response.reference
			payment.alreadyCharged.value   = !!response.alreadyCharged
			mnoDetection.reference         = response.reference
			instructions.value             = response.instructions ?? null
			selectedMethod.value           = response.method

			payment.lockPaymentProvider(response.provider)

			if (response.paymentPurpose) {
				payment.lockPaymentPurpose(response.paymentPurpose)
			}

			// Card redirect — usePayment handles the window.location.replace
			if (response.flow === 'redirect') return

			// Daraja callback — polling already started inside usePayment.
			// Increment retryCount so showRecoveryAction escalates sooner.
			if (response.flow === 'callback') {
				payment.retryCount.value += 1
				instructions.value = response.instructions ?? 'Check your phone and enter your M-Pesa PIN'
				return
			}

			if (response.flow === 'mobile_direct') {
				const isSelectedValid = applyMnoDetection(response)

				// Already charged — polling is running
				if (response.alreadyCharged) {
					payment.retryCount.value += 1
					return
				}

				// Provider still needs explicit selection before we can charge
				if (!isSelectedValid && response.requiresProviderSelection) {
					mnoDetection.state        = 'requires-selection'
					mnoDetection.showSelector = true
					mnoDetection.options      = response.options ?? []
					return
				}
			}

		} catch {
			// Errors surfaced via usePayment toast handling
		}
	}

	/**
	 * HYDRATE — restore full runtime state after page refresh or recovery.
	 *
	 * Reconstructs phone input, MNO detection state, mobilePaymentContext,
	 * instructions, and payment references from a HydratedPayment returned
	 * by usePaymentRecovery. Resumes polling if the session was in-flight.
	 *
	 * Guards: isHydrating prevents the phoneInput watcher from triggering
	 * provider re-detection while we're restoring values programmatically.
	 */
	function applyHydratedPayment(hydratedPayment: HydratedPayment) {
		console.log('[PaymentSession] hydrating payment session', hydratedPayment)

		isHydrating.value   = true
		payment.state.value = 'hydrating'

		if (hydratedPayment.method) {
			selectMethod(hydratedPayment.method)
		}

		// Phone
		if (hydratedPayment.phoneNumber) {
			phoneInput.value = hydratedPayment.phoneNumber

			const resolved = resolvePhone(hydratedPayment.phoneNumber)
			resolution.value = resolved

			if (hydratedPayment.phoneNumberRegion) {
				// Prefer backend-resolved region for accuracy
				detectedRegion.value    = hydratedPayment.phoneNumberRegion
				mnoDetection.country    = hydratedPayment.phoneNumberCountry ?? null
			} else if (resolved.region) {
				detectedRegion.value = resolved.region
			}
		}

		// Display context
		mobilePaymentContext.value = {
			displayAmount:          hydratedPayment.displayAmount,
			displayAmountFormatted: hydratedPayment.displayAmountFormatted,
			displayCurrency:        hydratedPayment.displayCurrency,
		}

		if (hydratedPayment.instructions) {
			instructions.value = hydratedPayment.instructions
		}

		// Payment references
		payment.activeReference.value = hydratedPayment.reference
		payment.alreadyCharged.value  = !!hydratedPayment.alreadyCharged
		payment.lockPaymentProvider(hydratedPayment.provider)

		if (hydratedPayment.paymentPurpose) {
			payment.lockPaymentPurpose(hydratedPayment.paymentPurpose)
		}

		// MNO detection state
		applyMnoDetection(hydratedPayment)

		/**
		 * applyMnoDetection sets showSelector for detected/selected/requires-selection.
		 * Hydration must also show the selector for 'suggested' — the user was mid-selection
		 * before the page refreshed and should be returned to that state.
		 */
		mnoDetection.showSelector =
			mnoDetection.state === 'suggested' ||
			mnoDetection.state === 'requires-selection'

		// Resume or redirect

		/**
		 * Card sessions that have a redirectUrl are mid-redirect.
		 * Discard the recovery (it was the old session), re-redirect.
		 */
		if (hydratedPayment.method === 'card' && hydratedPayment.redirectUrl) {
			discardRecovery()
			window.location.replace(hydratedPayment.redirectUrl)
			return
		}

		const shouldResumePolling = routing.shouldStartPolling(hydratedPayment)

		payment.state.value = shouldResumePolling ? 'processing' : 'idle'

		if (shouldResumePolling) {
			payment.startPolling(hydratedPayment.reference, hydratedPayment.flow, onTerminal)
		}

		// Defer isHydrating = false until after Vue has flushed
		// so phoneInput watcher doesn't fire on the restored value
		requestAnimationFrame(() => {
			isHydrating.value = false
		})
	}

	/**
	 * CANCEL — frontend runtime reset only.
	 *
	 * Stops polling and clears all local session state.
	 * Does NOT cancel the provider-side payment or mutate
	 * backend payment state — the session remains in whatever
	 * state the backend left it in.
	 */
	async function cancelSession() {
		await payment.cancelActivePaymentSession()
		resetPaymentSession()
	}

	return {
		// Session lifecycle
		handlePay,
		initiateSession,
		chargeSession,
		retrySession,
		applyHydratedPayment,
		cancelSession,
		resetPaymentSession,
		handleTerminalReset,
		// Template state
		instructions,
		processingStage,
		buttonLabel,
		paymentMessage,
	}
}
