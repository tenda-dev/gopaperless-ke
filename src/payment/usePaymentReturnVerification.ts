import { onBeforeUnmount } from 'vue'

import { useNetworkState } from '@/composables/useNetworkState'
import { paymentDriver } from './drivers/paymentDriver'

export interface PaymentReturnVerificationOptions {
	/**
	 * Provider reference returned by DPO
	 */
	providerReference: string

	/**
	 * Delay before the first provider verification (Phase 2).
	 *
	 * Gives DPO time to complete redirect processing before we ask.
	 *
	 * @default 5_000
	 */
	initialDelay?: number

	/**
	 * How often to poll the backend for payment status (Phase 3).
	 *
	 * Every tick calls getPaymentStatus() unless a verifyPayment()
	 * is due on that tick instead.
	 *
	 * @default 3_000
	 */
	pollInterval?: number

	/**
	 * How often to re-verify with the provider during Phase 3.
	 *
	 * Must be a multiple of pollInterval in practice.
	 * Over a 40s window this produces ~3 verifyPayment() calls.
	 *
	 * @default 15_000
	 */
	verifyInterval?: number

	/**
	 * Total wall-clock budget for reconciliation.
	 *
	 * Offline pauses are excluded from this budget.
	 *
	 * @default 40_000
	 */
	timeout?: number

	// --- Lifecycle callbacks ------------------------------------------------

	/** Payment reached SUCCESS. */
	onSuccess(): void | Promise<void>

	/** Payment reached FAILED. */
	onFailure(): void | Promise<void>

	/** Payment was CANCELLED by the customer. */
	onCancelled?(): void | Promise<void>

	/**
	 * Reconciliation window elapsed without a terminal state.
	 *
	 * This is NOT a payment failure — the backend may still reconcile.
	 * Callers should present a "Retry" option that calls `restart()`.
	 */
	onTimeout(): void | Promise<void>
}

/**
 * Manages the DPO card payment return reconciliation lifecycle.
 *
 * Phase 1 — Immediate local state check (getPaymentStatus)
 *   Already terminal → done, no provider call needed.
 *
 * Phase 2 — First provider verification (verifyPayment) after initialDelay.
 *   Gives DPO time to finish redirect processing before we ask.
 *
 * Phase 3 — Single chained ticker every pollInterval (default 3s).
 *   Each tick asks: has verifyInterval (default 15s) elapsed since the last
 *   provider call?  If yes → verifyPayment().  If no → getPaymentStatus().
 *
 *   Over the default 40s budget this yields:
 *     ~3 verifyPayment() calls  (at 5s, 20s, 35s)
 *     ~10–13 getPaymentStatus() calls
 *
 * Offline handling:
 *   - Timers pause; the timeout budget does not advance while offline.
 *   - Reconnect / tab-visible triggers an immediate status check.
 *
 * Duplicate prevention:
 *   - At most one in-flight request at any time.
 *   - Chained setTimeout (not setInterval) guarantees stable cadence even
 *     on slow networks.
 */
export function usePaymentReturnVerification(
	options: PaymentReturnVerificationOptions,
) {
	const {
		isOffline,
		markOffline,
		markOnline,
		isConnectivityError,
		onReconnect,
	} = useNetworkState()


	const INITIAL_DELAY    = options.initialDelay   ?? 5_000
	const POLL_INTERVAL    = options.pollInterval   ?? 3_000
	const VERIFY_INTERVAL  = options.verifyInterval ?? 15_000
	const TIMEOUT_BUDGET   = options.timeout        ?? 40_000

	/** Whether the lifecycle has been stopped. */
	let stopped = false

	/** Whether Phase 2 (first provider verification) has completed. */
	let initialVerificationDone = false

	/**
	 * Elapsed-ms timestamp of the last verifyPayment() call.
	 *
	 * Used to decide whether Phase 3 ticks should call verifyPayment()
	 * or getPaymentStatus().
	 */
	let lastVerifiedAtMs = 0

	/** Whether a network request is currently in-flight. */
	let requestInFlight = false

	/** Whether a request was skipped due to being offline. */
	let requestInterrupted = false

	/** ID of the currently scheduled setTimeout. */
	let scheduledTimer: ReturnType<typeof setTimeout> | null = null

	/** Cleanup fn returned by onReconnect. */
	let unsubscribeReconnect: (() => void) | null = null

	/**
	 * Wall-clock ms consumed by the reconciliation so far.
	 *
	 * Advances only while online.  Pauses while offline so a connectivity
	 * blip does not unfairly consume the user's reconciliation budget.
	 */
	let elapsedMs = 0

	/** Timestamp of the last time elapsed tracking started / resumed. */
	let elapsedAnchor: number | null = null


	function resumeElapsed() {
		if (elapsedAnchor === null) {
			elapsedAnchor = Date.now()
		}
	}

	function pauseElapsed() {
		if (elapsedAnchor !== null) {
			elapsedMs += Date.now() - elapsedAnchor
			elapsedAnchor = null
		}
	}

	function snapshotElapsed(): number {
		if (elapsedAnchor !== null) {
			return elapsedMs + (Date.now() - elapsedAnchor)
		}
		return elapsedMs
	}

	function clearTimer() {
		if (scheduledTimer !== null) {
			clearTimeout(scheduledTimer)
			scheduledTimer = null
		}
	}

	/**
	 * Schedule the next reconciliation tick.
	 *
	 * Uses chained setTimeout so a slow network response never causes
	 * overlapping requests.
	 */
	function scheduleNext(delay: number) {
		if (stopped) return

		clearTimer()

		scheduledTimer = setTimeout(() => {
			scheduledTimer = null
			void tick()
		}, delay)
	}

	/**
	 * One reconciliation tick.
	 *
	 * Decision tree:
	 *   Phase 2 not done yet           → verifyPayment()   (first call)
	 *   Phase 3, verify cadence due    → verifyPayment()   (~every 15s)
	 *   Phase 3, verify cadence not due → getPaymentStatus() (~every 3s)
	 *
	 * After a terminal result (SUCCESS / FAILED / CANCELLED) the lifecycle
	 * stops and the appropriate callback fires.
	 *
	 * On PENDING the next tick is scheduled and we continue.
	 */
	async function tick() {
		if (stopped) return

		// Never overlap requests
		if (requestInFlight) return

		// Pause when offline; reconnect handler will resume
		if (isOffline.value) {
			pauseElapsed()
			requestInterrupted = true
			return
		}

		// Check timeout budget before spending a request
		if (snapshotElapsed() >= TIMEOUT_BUDGET) {
			await fireTimeout()
			return
		}

		const elapsed = snapshotElapsed()

		const verifyDue =
			!initialVerificationDone ||
			(elapsed - lastVerifiedAtMs) >= VERIFY_INTERVAL

		requestInFlight = true

		try {
			let res

			if (verifyDue) {
				res = await paymentDriver.verifyPayment(options.providerReference)
				lastVerifiedAtMs       = elapsed
				initialVerificationDone = true
			} else {
				res = await paymentDriver.getPaymentStatus(options.providerReference)
			}

			// Confirm connectivity if we thought we were offline
			if (isOffline.value) {
				markOnline()
				resumeElapsed()
			}

			switch (res.status) {
				case 'SUCCESS':
					stop()
					await options.onSuccess()
					return

				case 'FAILED':
					stop()
					await options.onFailure()
					return

				case 'CANCELLED':
					stop()
					await options.onCancelled?.()
					return

				case 'PENDING':
				default:
					// Re-check timeout before scheduling the next poll
					if (snapshotElapsed() >= TIMEOUT_BUDGET) {
						await fireTimeout()
						return
					}
					scheduleNext(POLL_INTERVAL)
					return
			}
		} catch (err) {
			if (isConnectivityError(err)) {
				// Transient connectivity issue — pause and wait for reconnect
				markOffline()
				pauseElapsed()
				requestInterrupted = true
				return
			}

			// Non-network error (e.g. 4xx/5xx, unexpected shape)
			// Stop the lifecycle and surface to the caller
			stop()
			throw err
		} finally {
			requestInFlight = false
		}
	}

	async function fireTimeout() {
		stop()
		await options.onTimeout()
	}

	// -----------------------------------------------------------------------
	// Phase 1 — immediate local state check
	// -----------------------------------------------------------------------

	/**
	 * Ask the backend for the persisted payment state.
	 *
	 * This never contacts DPO.  If the payment is already terminal we short-
	 * circuit and never enter Phase 2 at all.
	 *
	 * Returns true when the state was terminal (lifecycle ended).
	 */
	async function checkLocalState(): Promise<boolean> {
		try {
			const res = await paymentDriver.getPaymentStatus(
				options.providerReference,
			)

			switch (res.status) {
				case 'SUCCESS':
					stop()
					await options.onSuccess()
					return true

				case 'FAILED':
					stop()
					await options.onFailure()
					return true

				case 'CANCELLED':
					stop()
					await options.onCancelled?.()
					return true

				default:
					// PENDING — continue to Phase 2
					return false
			}
		} catch (err) {
			if (isConnectivityError(err)) {
				// Can't reach the backend yet — skip straight to Phase 2
				// which will handle offline similarly
				markOffline()
				pauseElapsed()
				return false
			}

			stop()
			throw err
		}
	}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Begin the reconciliation lifecycle.
	 *
	 * Safe to call multiple times — always resets internal state first.
	 */
	async function start() {
		// Full reset so restart() works correctly
		stop()

		stopped                 = false
		initialVerificationDone = false
		lastVerifiedAtMs        = 0
		requestInFlight         = false
		requestInterrupted      = false
		elapsedMs               = 0
		elapsedAnchor           = null

		resumeElapsed()

		// ── Phase 1: check local backend state immediately ──────────────────
		const alreadyTerminal = await checkLocalState()

		if (alreadyTerminal || stopped) return

		// ── Subscribe to reconnect / tab-visible events ─────────────────────
		unsubscribeReconnect = onReconnect(() => {
			if (stopped) return

			if (requestInterrupted) {
				requestInterrupted = false
			}

			resumeElapsed()
			clearTimer()

			// Immediate tick on reconnect — don't wait for the next interval
			void tick()
		})

		// ── Phase 2: first provider verification after initial delay ────────
		// Subsequent verify calls happen inside tick() on the VERIFY_INTERVAL
		// cadence, interleaved with getPaymentStatus() polls.
		scheduleNext(INITIAL_DELAY)
	}

	/**
	 * Restart the lifecycle from the beginning (Phase 1).
	 *
	 * Intended to be called from the timeout "Retry" action in the UI.
	 */
	function restart() {
		void start()
	}

	/**
	 * Stop the lifecycle entirely and clean up all resources.
	 *
	 * Safe to call multiple times.
	 */
	function stop() {
		stopped = true

		clearTimer()
		pauseElapsed()

		if (unsubscribeReconnect) {
			unsubscribeReconnect()
			unsubscribeReconnect = null
		}

		requestInFlight    = false
		requestInterrupted = false
	}

	onBeforeUnmount(() => {
		stop()
	})

	return {
		start,
		stop,
		restart,
	}
}
