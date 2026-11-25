/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Isolated with a mocked coordinator and mocked @vueuse/core primitives:
 * this composable's only real job is to wire visibility + an interval to
 * the coordinator's own refreshIfStale/refreshNow, and to import nothing
 * else (no direct store access -- "composable imports only the
 * coordinator" is itself a test-matrix requirement).
 *
 * Mock factories are hoisted above every top-level const in this file, so
 * each factory builds its own state internally (no outer-scope capture)
 * and exposes test hooks as extra named exports of the mocked module,
 * which the test body then imports normally.
 */
import { effectScope, nextTick } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/payment/entitlementCoordinator', () => ({
	entitlementCoordinator: {
		refreshIfStale: vi.fn(),
		refreshNow: vi.fn(),
	},
}))

vi.mock('@vueuse/core', async () => {
	const { ref } = await import('vue')
	const visibility = ref<'visible' | 'hidden'>('visible')
	const pause = vi.fn()
	const resume = vi.fn()
	let intervalCallback: (() => void) | null = null
	const useIntervalFn = vi.fn((cb: () => void) => {
		intervalCallback = cb
		return { pause, resume, isActive: ref(true) }
	})

	return {
		useDocumentVisibility: () => visibility,
		useIntervalFn,
		__visibility: visibility,
		__pause: pause,
		__resume: resume,
		__getIntervalCallback: () => intervalCallback,
		__useIntervalFn: useIntervalFn,
	}
})

import { useEntitlementSync } from '@/composables/useEntitlementSync'
import { entitlementCoordinator } from '@/payment/entitlementCoordinator'
import * as vueuseMock from '@vueuse/core'

const refreshIfStale = entitlementCoordinator.refreshIfStale as unknown as ReturnType<typeof vi.fn>
const refreshNow = entitlementCoordinator.refreshNow as unknown as ReturnType<typeof vi.fn>
const visibility = (vueuseMock as any).__visibility
const pause = (vueuseMock as any).__pause as ReturnType<typeof vi.fn>
const resume = (vueuseMock as any).__resume as ReturnType<typeof vi.fn>
const getIntervalCallback = (vueuseMock as any).__getIntervalCallback as () => (() => void) | null
const useIntervalFnMock = (vueuseMock as any).__useIntervalFn as ReturnType<typeof vi.fn>

beforeEach(() => {
	vi.clearAllMocks()
	visibility.value = 'visible'
})

describe('useEntitlementSync', () => {
	it('each interval tick calls refreshIfStale (opportunistic, coordinator-gated)', () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT'))

		expect(getIntervalCallback()).not.toBeNull()
		getIntervalCallback()!()

		expect(refreshIfStale).toHaveBeenCalledWith('SIGN_DOCUMENT')
		expect(refreshNow).not.toHaveBeenCalled()

		scope.stop()
	})

	it('pauses the interval when the document becomes hidden', async () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT'))

		visibility.value = 'hidden'
		await nextTick()

		expect(pause).toHaveBeenCalledTimes(1)
		expect(resume).not.toHaveBeenCalled()

		scope.stop()
	})

	it('forces an authoritative refresh and resumes on hidden -> visible', async () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT'))

		visibility.value = 'hidden'
		await nextTick()
		visibility.value = 'visible'
		await nextTick()

		expect(refreshNow).toHaveBeenCalledWith('SIGN_DOCUMENT')
		expect(resume).toHaveBeenCalledTimes(1)

		scope.stop()
	})

	it('stops the interval and the visibility watcher on scope disposal (unmount)', async () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT'))

		scope.stop() // onScopeDispose calls pause() once as part of teardown
		const callsAtDisposal = pause.mock.calls.length

		visibility.value = 'hidden' // after disposal -- the watcher must be stopped, so this adds nothing
		await nextTick()
		expect(pause).toHaveBeenCalledTimes(callsAtDisposal)
	})

	it('defaults to SIGN_DOCUMENT when no product code is given', () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync())

		getIntervalCallback()!()

		expect(refreshIfStale).toHaveBeenCalledWith('SIGN_DOCUMENT')

		scope.stop()
	})

	it('installs no polling interval when poll is false', () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT', { poll: false }))

		expect(useIntervalFnMock).not.toHaveBeenCalled()

		scope.stop()
	})

	it('still forces a refresh on hidden -> visible when poll is false, touching no interval', async () => {
		const scope = effectScope()
		scope.run(() => useEntitlementSync('SIGN_DOCUMENT', { poll: false }))

		visibility.value = 'hidden'
		await nextTick()
		visibility.value = 'visible'
		await nextTick()

		expect(refreshNow).toHaveBeenCalledWith('SIGN_DOCUMENT')
		expect(pause).not.toHaveBeenCalled()
		expect(resume).not.toHaveBeenCalled()

		scope.stop()
	})
})
