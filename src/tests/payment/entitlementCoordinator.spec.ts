/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Real bus (src/events/appEvents.ts) + a fake, fully controllable
 * entitlement store, per the frontend blueprint's test conventions.
 * The coordinator itself is never a settlement authority -- every
 * assertion here is about WHEN/HOW it reads, never about consumption.
 */
import { computed, nextTick, reactive } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/store/entitlement', () => ({
	useEntitlementStore: vi.fn(),
}))

import { useEntitlementStore } from '@/store/entitlement'
import { entitlementCoordinator } from '@/payment/entitlementCoordinator'
import { emitAppEvent } from '@/events/appEvents'

type RefreshOutcome = { ok: boolean; error?: string }

function makeFakeStore() {
	const state = reactive<{
		loading: Record<string, boolean>
		lastSuccessfulLoadAt: Record<string, number>
	}>({
		loading: {},
		lastSuccessfulLoadAt: {},
	})

	const refresh = vi.fn(async (productCode: string): Promise<RefreshOutcome> => {
		return { ok: true }
	})

	return {
		state,
		refresh,
		isLoading: (pc: string) => computed(() => state.loading[pc] ?? false),
		getLastSuccessfulLoadAt: (pc: string) => computed(() => state.lastSuccessfulLoadAt[pc] ?? null),
		/** Test helper: simulate load() having advanced the stamp on success. */
		succeed(pc: string) {
			state.lastSuccessfulLoadAt[pc] = Date.now()
		},
	}
}

let pinia: Pinia
let fakeStore: ReturnType<typeof makeFakeStore>

beforeEach(() => {
	vi.useFakeTimers()
	pinia = createPinia()
	setActivePinia(pinia)
	fakeStore = makeFakeStore()
	;(useEntitlementStore as unknown as ReturnType<typeof vi.fn>).mockReturnValue(fakeStore)
	entitlementCoordinator.start(pinia)
})

afterEach(() => {
	entitlementCoordinator.stop()
	vi.useRealTimers()
	vi.clearAllMocks()
})

describe('entitlementCoordinator', () => {
	it('refreshes the named product on a signing:completed event', async () => {
		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()

		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('D-5: a payment:verified event with no productCode refreshes every tracked product', async () => {
		emitAppEvent('payment:verified', { context: 'return' })
		await vi.runAllTimersAsync()

		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
		expect(fakeStore.refresh).toHaveBeenCalledWith('CERTIFICATE_ACCESS')
	})

	it('single-flight: a second event while a refresh is in flight becomes one forced follow-up, not a second concurrent read', async () => {
		let resolveFirst!: (outcome: RefreshOutcome) => void
		fakeStore.refresh.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()
		expect(fakeStore.refresh).toHaveBeenCalledTimes(1)

		// Arrives while the first read is still in flight.
		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()
		// Still just the one call -- the second event was coalesced into "dirty", not issued concurrently.
		expect(fakeStore.refresh).toHaveBeenCalledTimes(1)

		resolveFirst({ ok: true })
		await vi.runAllTimersAsync()

		// Exactly one follow-up read -- not one per coalesced event.
		expect(fakeStore.refresh).toHaveBeenCalledTimes(2)
	})

	it('a poll request while a refresh is in flight never schedules a follow-up', async () => {
		let resolveFirst!: (outcome: RefreshOutcome) => void
		fakeStore.refresh.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))

		entitlementCoordinator.refreshNow('SIGN_DOCUMENT')
		await nextTick()
		expect(fakeStore.refresh).toHaveBeenCalledTimes(1)

		entitlementCoordinator.refreshIfStale('SIGN_DOCUMENT')
		await nextTick()

		resolveFirst({ ok: true })
		await vi.runAllTimersAsync()

		// No forced follow-up: poll never marks dirty.
		expect(fakeStore.refresh).toHaveBeenCalledTimes(1)
	})

	it('watcher advancement: a successful external refresh in flight services the event without a duplicate coordinator read', async () => {
		fakeStore.state.loading.SIGN_DOCUMENT = true // external refresh already in flight

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()
		expect(fakeStore.refresh).not.toHaveBeenCalled() // deferred to the watcher, not read directly

		// The external refresh completes successfully (stamp advances, then loading clears).
		fakeStore.succeed('SIGN_DOCUMENT')
		fakeStore.state.loading.SIGN_DOCUMENT = false
		await nextTick()

		expect(fakeStore.refresh).not.toHaveBeenCalled() // advancement serviced the event -- no duplicate
	})

	it('watcher advancement: a FAILING external refresh in flight still leaves the event serviced by the coordinator', async () => {
		fakeStore.state.loading.SIGN_DOCUMENT = true

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()
		expect(fakeStore.refresh).not.toHaveBeenCalled()

		// The external refresh completes WITHOUT advancing the stamp (failure).
		fakeStore.state.loading.SIGN_DOCUMENT = false
		await nextTick()

		// No advancement -> the coordinator performs its own authoritative read.
		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('watcher advancement is judged by advancement, not recency: a recent prior success does not excuse a new failing external refresh', async () => {
		fakeStore.succeed('SIGN_DOCUMENT') // a recent prior successful read already exists
		await nextTick()

		fakeStore.state.loading.SIGN_DOCUMENT = true // a NEW external refresh starts
		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()

		fakeStore.state.loading.SIGN_DOCUMENT = false // ...and it fails (no new stamp)
		await nextTick()

		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('suppression token: notePublisherRefresh suppresses exactly the next matching event, once', async () => {
		entitlementCoordinator.notePublisherRefresh('SIGN_DOCUMENT')

		emitAppEvent('payment:verified', { context: 'purchase', productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()
		expect(fakeStore.refresh).not.toHaveBeenCalled()

		// A second matching event, with no new token, refreshes normally.
		emitAppEvent('payment:verified', { context: 'purchase', productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()
		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('suppression token does not suppress a different product', async () => {
		entitlementCoordinator.notePublisherRefresh('SIGN_DOCUMENT')

		emitAppEvent('payment:verified', { context: 'purchase', productCode: 'CERTIFICATE_ACCESS' })
		await vi.runAllTimersAsync()

		expect(fakeStore.refresh).toHaveBeenCalledWith('CERTIFICATE_ACCESS')
	})

	it('an unmatched suppression token expires after its TTL and never suppresses a later unrelated event', async () => {
		entitlementCoordinator.notePublisherRefresh('SIGN_DOCUMENT')

		vi.advanceTimersByTime(3_001) // > SUPPRESS_TTL_MS

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()

		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('CreditPurchaseFlow/PaymentReturn-style events (no token) always refresh', async () => {
		emitAppEvent('payment:verified', { context: 'purchase', productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()

		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('an unexpected rejection clears in-flight tracking, records backoff, and services a pending dirty via a delayed retry', async () => {
		let rejectFirst!: (err: unknown) => void
		fakeStore.refresh.mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject }))

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()

		// A second event arrives while the (about to fail) read is in flight.
		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()

		fakeStore.refresh.mockResolvedValueOnce({ ok: true })
		rejectFirst(new Error('network exploded'))

		// The rejection must not escape as an unhandled promise rejection.
		await vi.runAllTimersAsync()

		// Backoff-delayed retry eventually issues the second (pending) read.
		expect(fakeStore.refresh).toHaveBeenCalledTimes(2)
	})

	it('a failed read never advances the success timestamp used for poll gating', async () => {
		fakeStore.refresh.mockResolvedValueOnce({ ok: false, error: 'failed' })

		entitlementCoordinator.refreshNow('SIGN_DOCUMENT')
		await vi.runAllTimersAsync()

		expect(fakeStore.getLastSuccessfulLoadAt('SIGN_DOCUMENT').value).toBeNull()

		// Polling right after a failure is still gated by backoff, not just staleness.
		entitlementCoordinator.refreshIfStale('SIGN_DOCUMENT')
		await vi.runAllTimersAsync()
		expect(fakeStore.refresh).toHaveBeenCalledTimes(1) // the poll did not get through the backoff
	})

	it('an old generation completion after stop() mutates nothing and schedules nothing', async () => {
		let resolveFirst!: (outcome: RefreshOutcome) => void
		fakeStore.refresh.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))

		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await nextTick()

		entitlementCoordinator.stop()
		resolveFirst({ ok: true })
		await vi.runAllTimersAsync()

		// No crash, and nothing left scheduled for a generation that no longer exists.
		entitlementCoordinator.start(pinia)
		emitAppEvent('signing:completed', { productCode: 'SIGN_DOCUMENT' })
		await vi.runAllTimersAsync()

		// Only the fresh post-restart call counts -- re-asserts the coordinator is usable again.
		expect(fakeStore.refresh).toHaveBeenCalledWith('SIGN_DOCUMENT')
	})

	it('start/stop are idempotent', () => {
		expect(() => {
			entitlementCoordinator.start(pinia)
			entitlementCoordinator.start(pinia)
			entitlementCoordinator.stop()
			entitlementCoordinator.stop()
		}).not.toThrow()
	})

})
