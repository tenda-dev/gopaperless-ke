/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { watch } from 'vue'
import type { Pinia } from 'pinia'
import { useEntitlementStore } from '@/store/entitlement'
import { onAppEvent } from '@/events/appEvents'
import type { ProductCode } from '@/constants/product'

const POLL_STALE_MS = 20_000 // Minimum freshness window for polling
const BACKOFF_BASE_MS = 5_000
const BACKOFF_MAX_MS = 60_000
// Bounds publisher suppression tokens so an unmatched token cannot suppress
// an unrelated event arriving later.
const SUPPRESS_TTL_MS = 3_000

// Products whose entitlement state is maintained by this coordinator.
const TRACKED: ProductCode[] = ['SIGN_DOCUMENT', 'CERTIFICATE_ACCESS']

type Reason = 'event' | 'poll' | 'focus'
type Store = ReturnType<typeof useEntitlementStore>

let active = false
// Invalidates asynchronous work from previous lifecycle instances. Continuations
// must match the current generation before mutating state or scheduling work.
let generation = 0
let store: Store | null = null
let unsubs: Array<() => void> = []

const coordInFlight = new Map<ProductCode, Promise<void>>()
const dirty = new Set<ProductCode>()
const backoffUntil = new Map<ProductCode, number>()
const failures = new Map<ProductCode, number>()
const pendingExternal = new Map<ProductCode, () => void>()
const suppressNext = new Map<ProductCode, number>()
const retryTimers = new Map<ProductCode, ReturnType<typeof setTimeout>>()

/**
 * Entitlement coordinator for frontend refresh lifecycle.
 *
 * This coordinator only synchronizes displayed entitlement state;.
 *
 * Its store-free public API centralizes refresh, suppression, and backoff
 * policies so consumers do not implement their own coordination.
 */
export const entitlementCoordinator = {
	start(pinia: Pinia): void {
		if (active) {
			return
		}

		const s = useEntitlementStore(pinia)
		const registered: Array<() => void> = []

		// Incremental registration with rollback: if a later onAppEvent
		// call throws, every earlier subscription from this start() is
		// torn down rather than left as a half-wired coordinator.
		try {
			registered.push(onAppEvent('signing:completed', (p) => invalidate(p.productCode)))
			registered.push(onAppEvent('payment:verified', (p) => invalidate(p.productCode)))
			registered.push(onAppEvent('reservation:created', (p) => invalidate(p.productCode)))
			registered.push(onAppEvent('reservation:released', (p) => invalidate(p.productCode)))
		} catch (err) {
			registered.forEach((off) => {
				try {
					off()
				} catch {
					// Continue cleanup even if an individual unsubscribe fails.
				}
			})
			throw err
		}

		store = s
		unsubs = registered
		active = true
	},

	stop(): void {
		if (!active) {
			return
		}

		generation++

		unsubs.forEach((off) => {
			try {
				off()
			} catch {
				// Cleanup must continue even if one listener cannot be removed.
			}
		})
		unsubs = []

		pendingExternal.forEach((stop) => stop())
		pendingExternal.clear()

		retryTimers.forEach((timer) => clearTimeout(timer))
		retryTimers.clear()

		coordInFlight.clear()
		dirty.clear()
		backoffUntil.clear()
		failures.clear()
		suppressNext.clear()

		store = null
		active = false
	},

	refreshIfStale(productCode: ProductCode = 'SIGN_DOCUMENT'): void {
		request(productCode, 'poll')
	},

	refreshNow(productCode: ProductCode = 'SIGN_DOCUMENT'): void {
		request(productCode, 'focus')
	},

	/**
	 * A publisher that just performed its OWN authoritative
	 * entitlementStore.refresh(productCode) calls this immediately before
	 * emitting its event, to suppress the one matching coordinator read
	 * that would otherwise be a pure duplicate. Scoped to exactly the
	 * publisher(s) that do their own direct refresh -- every other event
	 * carries no token and always refreshes (see appEvents contracts: no
	 * suppression hint travels in the payload itself).
	 */
	notePublisherRefresh(productCode: ProductCode): void {
		if (active) {
			suppressNext.set(productCode, Date.now() + SUPPRESS_TTL_MS)
		}
	},
}

function invalidate(productCode?: ProductCode): void {
	;(productCode ? [productCode] : TRACKED).forEach((pc) => request(pc, 'event'))
}

function consumeSuppression(productCode: ProductCode): boolean {
	const expiry = suppressNext.get(productCode)
	if (expiry === undefined) {
		return false
	}
	suppressNext.delete(productCode) // one-shot regardless of outcome
	return Date.now() < expiry
}

function request(productCode: ProductCode, reason: Reason): void {
	if (!active || !store) {
		return
	}

	// Suppress only an explicitly marked publisher event. Recency alone
	// cannot guarantee that a refresh captured the change represented by
	// a new event.
	if (reason === 'event' && consumeSuppression(productCode)) {
		return
	}

	// Coalesce events received during a coordinator refresh into one
	// follow-up read. Polling and focus requests do not queue follow-ups.
	if (coordInFlight.has(productCode)) {
		if (reason === 'event') {
			dirty.add(productCode)
		}
		return
	}

	// An external refresh is already running. Only events require
	// reassessment after it completes because they represent invalidations.
	if (store.isLoading(productCode).value) {
		if (reason === 'event') {
			watchExternalThenReassess(productCode)
		}
		return
	}

	// Apply freshness and backoff limits only to opportunistic polling.
	// Events and foreground requests must not be blocked by these limits.
	if (reason === 'poll') {
		const now = Date.now()
		if (now < (backoffUntil.get(productCode) ?? 0)) {
			return
		}
		if (now - (store.getLastSuccessfulLoadAt(productCode).value ?? 0) < POLL_STALE_MS) {
			return
		}
	}

	startRefresh(productCode)
}

function startRefresh(productCode: ProductCode): void {
	const gen = generation
	const inFlight = (async () => {
		let ok = false
		try {
			const outcome = await store!.refresh(productCode)
			ok = outcome.ok
		} catch (err) {
			// Ensure an unexpected rejection does not leave this product permanently
			// marked as in-flight. Refresh normally resolves with an outcome.
			console.warn('[entitlementCoordinator] unexpected refresh rejection', err)
			ok = false
		} finally {
			if (gen === generation) {
				coordInFlight.delete(productCode)

				if (ok) {
					failures.delete(productCode)
					backoffUntil.delete(productCode)
				} else {
					const count = (failures.get(productCode) ?? 0) + 1
					failures.set(productCode, count)
					backoffUntil.set(
						productCode,
						Date.now() + Math.min(BACKOFF_BASE_MS * 2 ** (count - 1), BACKOFF_MAX_MS),
					)
				}

				if (dirty.delete(productCode)) {
					serviceDirty(productCode, ok)
				}
			}
		}
	})()
	coordInFlight.set(productCode, inFlight)
}

/**
 * Services events received during an in-flight coordinator refresh.
 *
 * A successful refresh is followed by an immediate read to ensure the
 * event's change was captured. After a failure, the follow-up is delayed
 * according to the coordinator's backoff policy.
 */
function serviceDirty(productCode: ProductCode, lastOk: boolean): void {
	if (lastOk) {
		issueRefresh(productCode)
		return
	}

	const gen = generation
	const delay = Math.max(0, (backoffUntil.get(productCode) ?? 0) - Date.now())
	const timer = setTimeout(() => {
		retryTimers.delete(productCode)
		if (active && gen === generation) {
			issueRefresh(productCode)
		}
	}, delay)
	retryTimers.set(productCode, timer)
}

// Recheck in-flight conditions because another refresh may have started
// since the follow-up was scheduled.
function issueRefresh(productCode: ProductCode): void {
	if (!active || !store) {
		return
	}
	if (coordInFlight.has(productCode)) {
		dirty.add(productCode)
		return
	}
	if (store.isLoading(productCode).value) {
		watchExternalThenReassess(productCode)
		return
	}
	startRefresh(productCode)
}

/**
 * Services an event that arrived during an external refresh.
 *
 * The event is considered serviced only if the successful-load timestamp
 * advances while that refresh is running. Completion alone is insufficient:
 * a failed refresh must not cause the invalidation to be discarded.
 *
 * The synchronous watcher is required to observe every loading transition.
 * Vue's default batched watcher can coalesce a rapid completion and restart,
 * hiding the intermediate state and leaving the event unserviced.
 *
 * Registration occurs synchronously after detecting the external loading
 * state, before the refresh can finish and clear it.
 */
function watchExternalThenReassess(productCode: ProductCode): void {
	if (pendingExternal.has(productCode)) {
		// Reuse the pending watcher so concurrent events require only one reassessment.
		return
	}

	const gen = generation
	const stampAtRegister = store!.getLastSuccessfulLoadAt(productCode).value ?? 0

	const stopWatch = watch(
		() => store!.isLoading(productCode).value,
		(loading, wasLoading) => {
			if (wasLoading && !loading) {
				stopWatch()
				pendingExternal.delete(productCode)

				if (!active || gen !== generation) {
					return
				}

				const stampAfter = store!.getLastSuccessfulLoadAt(productCode).value ?? 0
				if (stampAfter > stampAtRegister) {
					return // The external refresh succeeded and serviced the event.
				}

				startRefresh(productCode) // The event remains unserviced; perform our own refresh.
			}
		},
		{ flush: 'sync' },
	)

	pendingExternal.set(productCode, stopWatch)
}
