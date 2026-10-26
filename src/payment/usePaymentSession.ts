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
	SelectedMno,
} from '@/payment'
import { showError, showInfo, showSuccess } from '@/services/toast'
import type { UserContext } from '@/store/userContext'
import type { MnoDetectionPayload, MnoDetectionState } from './useMnoDetection'
import { useNetworkState } from '@/composables/useNetworkState'

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
	isDaraja: Ref<boolean>
	resolvedSignUuid: Ref<string | null>
	isValidSelectedProvider: (selected?: SelectedMno | null) => boolean
	paymentDisplayLabel: ComputedRef<string | null>
}

export type RecoveryReason =
    | 'no_prompt'        // didn't receive STK push
    | 'dismissed'        // dismissed by accident
    | 'wrong_pin'        // entered wrong PIN
    | 'different_number' // wants to change number/method

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
	isDaraja,
	resolvedSignUuid,
	isValidSelectedProvider,
	paymentDisplayLabel,
}: PaymentSession) {

	const {
		isOffline,
	} = useNetworkState()

	/**
	 * Effective signer UUID: prefer the backend-resolved UUID cached in
	 * the component's resolvedSignUuid ref over the parent-supplied one.
	 * Single source of truth remains the component ref.
	 */
	const effectiveSignUuid = computed(() => resolvedSignUuid.value ?? sessionContext.signUuid ?? null)

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
	 * Recovery
	 */
	const isRecovering = ref(false)
	const isInvalidating = ref(false)

	/**
	 * Prevents the "connection lost" toast from firing on
	 * every polling tick while offline. Cleared on reconnect
	 * so the user sees the recovery toast exactly once.
	 */
	let hasShownOfflineToast = false

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

	const retryDisabled = computed(() =>
		payment.state.value === 'error'
    )

	//
	// Watchers
	//

	/**
	 * Advance processingStage (0 → 1 → 2) while the user
	 * is waiting for provider confirmation.
	 */
	watch(() => payment.state.value, (state) => {
		if (state === 'processing') {
			processingStage.value = 0
			setTimeout(() => (processingStage.value = 1), 2500)
			setTimeout(() => (processingStage.value = 2), 6000)
		}
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
			payment.state.value = 'error'
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

		const { signRequestId, productCode, quantity } = sessionContext

		// Phase 1
		if (!payment.activeReference.value) {

			payment.state.value = 'initiating'

			// Provider hint only -- backend remains the routing authority.
			const response = await payment.initiateOnly({
				provider:      isDaraja.value ? 'daraja' : 'dpo',
				paymentMethod: 'mobile',
				phoneNumber:   resolvedPhone.e164,
				signRequestId,
				signUuid:      effectiveSignUuid.value ?? undefined,
				productCode,
				userId:        user.value.uid,
				userEmail:     user.value.emailAddress,
				purpose:       payment.paymentPurpose.value!,
				quantity,
			})

			/**
			 * Defensive guard: a response without reference or flow
			 * is unrecoverable: we cannot poll or resume without them.
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

			// Cache backend-resolved signer UUID so phase-2 / card payloads
			// can use it when the parent supplied only signRequestId.
			if (response.signUuid) {
				resolvedSignUuid.value = response.signUuid
			}

			applyPaymentResponse(response)

			/**
			 * Daraja (callback) and any flow where the backend already
			 * dispatched provider execution: begin polling immediately.
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
			 * DPO mobile_direct: backend created the reference but has
			 * not charged yet. Set detection state only; when the backend
			 * flags autoCharge, charge immediately.
			 */
			switch (response.confidence) {
				case 'high':
					mnoDetection.state = 'detected'
					break
				case 'ambiguous':
					mnoDetection.state = 'suggested'
					break
				case 'unknown':
				default:
					mnoDetection.state = 'requires-selection'
					break
			}

			if (response.autoCharge?.enabled) {
				// Auto-charge requires the provider-native execution key.
				// autoCharge.mno is the canonical MNO identity and must not
				// be sent to the provider charge endpoint.
				const mno = response.autoCharge?.providerMnoKey
				const country = response.autoCharge?.country
				const reference = payment.activeReference.value
				const phoneNumber =
					mobilePaymentContext.value?.phoneNumber ??
					normalisedPhone.value

				const isSignRequest = (payment.paymentPurpose.value ?? sessionContext.purpose) === 'sign_request'

				if (isSignRequest && (!signRequestId || !effectiveSignUuid.value)) return

				if (mno && country && reference && phoneNumber) {
					mnoDetection.selected = {
						provider: mno,
						country: country,
					}
					mnoDetection.state = 'selected'

					await payment.chargeExistingReference(
						{
							reference:     payment.activeReference.value,
							phoneNumber,
							mno:           mnoDetection.selected.provider,
							mnoCountry:    mnoDetection.selected.country,
							signRequestId: signRequestId ?? 0,
							signUuid:      effectiveSignUuid.value ?? '',
						},
						onTerminal
					)

					return
				}
			}

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
		 * Final defensive guard before the charge call. For sign requests
		 * we also require signRequestId and the effective sign UUID, matching
		 * the live component.
		 */
		const isSignRequest = (payment.paymentPurpose.value ?? sessionContext.purpose) === 'sign_request'
		if (
			!payment.activeReference.value ||
			!phoneNumber                   ||
			!mnoToUse                      ||
			!countryToUse                  ||
			(isSignRequest && (!signRequestId || !effectiveSignUuid.value))
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
				signRequestId: signRequestId ?? 0,
				signUuid:      effectiveSignUuid.value ?? '',
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
				returnUrl:     payment.buildPaymentReturnUrl(),
				sponsorshipWorkflowFileUuid: sessionContext.sponsorshipWorkflowFileUuid,
				quantity:      sessionContext.quantity,
				purpose:       payment.paymentPurpose.value ?? 'sign_request',
				signRequestId: sessionContext.signRequestId,
				signUuid:      effectiveSignUuid.value ?? undefined,
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
	 */
	async function retrySession() {
		if (!user?.value) return

		try {
			const paymentMethod = selectedMethod.value === 'card' ? 'card' : 'mobile'
			const isCardPaymentMethod = paymentMethod === 'card'
			let redirectUrl: string | null = null
			let returnUrl: string | null = null

			if (isCardPaymentMethod) {
				redirectUrl = payment.buildPaymentRedirectUrl()
				returnUrl = payment.buildPaymentReturnUrl()
			}

			const payRes = await payment.startPayment(
				{
					signRequestId: sessionContext.signRequestId,
					signUuid:      effectiveSignUuid.value ?? undefined,
					paymentMethod,
					phoneNumber:   normalisedPhone.value || undefined,
					userEmail:     user.value.emailAddress,
					userId:        user.value.uid,
					productCode:   sessionContext.productCode,
					quantity:      sessionContext.quantity,
					purpose:       payment.paymentPurpose.value ?? 'sign_request',
					redirectUrl,
					returnUrl,
					// Scope the sponsorship pointer to the card flow; mobile retries never carry it.
					sponsorshipWorkflowFileUuid: isCardPaymentMethod ? sessionContext.sponsorshipWorkflowFileUuid : undefined,
				},
				onTerminal,
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

			payment.activeReference.value  = payRes.reference
			payment.lockPaymentProvider(payRes.provider)
			mnoDetection.reference         = payRes.reference
			instructions.value             = payRes.instructions ?? null
			selectedMethod.value           = payRes.method
			payment.alreadyCharged.value   = !!payRes.alreadyCharged

			if (payRes.paymentPurpose) {
				payment.lockPaymentPurpose(payRes.paymentPurpose)
			}

			if (payRes.signUuid) {
				resolvedSignUuid.value = payRes.signUuid
			}

			// Card redirect -- usePayment handles the window.location.replace
			if (payRes.flow === 'redirect') return

			// Daraja callback -- polling already started inside usePayment.
			// Increment retryCount so showRecoveryAction escalates sooner.
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

				if (isSelectedValid) {
					mnoDetection.state = 'selected'
					mnoDetection.showSelector = false
				} else {
					switch (payRes.confidence) {
						case 'high':
							mnoDetection.showSelector = false
							mnoDetection.state = 'detected'
							break
						case 'ambiguous':
							mnoDetection.showSelector = true
							mnoDetection.state = 'suggested'
							break
						case 'unknown':
						default:
							mnoDetection.showSelector = true
							mnoDetection.state = 'requires-selection'
							break
					}
				}

				mnoDetection.options = payRes.options ?? []
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

		if (hydratedPayment.method) {
			selectMethod(hydratedPayment.method)
		}

		payment.state.value = 'hydrating'

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


   /**
	* Enter recovery mode from the "Restart Payment" card.
	* No state reset — the user can still choose to keep waiting.
	*/
	function enterRecovery() {
		isRecovering.value = true
	}

   /**
	* Exit recovery without restarting (user explicitly chose "keep waiting").
	*/
	function exitRecovery() {
		isRecovering.value = false
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
		// Recovery
		isRecovering,
		isInvalidating,
		enterRecovery,
		exitRecovery,
		retryDisabled,
	}
}
