/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { emitAppEvent, onAppEvent } from '@/events/appEvents'

describe('appEvents', () => {
	beforeEach(() => {
		// console.error throws under the project's global test setup
		// (src/tests/setup.js); stubbed here specifically to assert
		// listener-isolation without that throw escaping the test.
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('delivers a typed payload to a subscriber', () => {
		const handler = vi.fn()
		const off = onAppEvent('signing:completed', handler)

		emitAppEvent('signing:completed', { signRequestUuid: 'uuid-1', productCode: 'SIGN_DOCUMENT' })

		expect(handler).toHaveBeenCalledTimes(1)
		expect(handler).toHaveBeenCalledWith({ signRequestUuid: 'uuid-1', productCode: 'SIGN_DOCUMENT' })

		off()
	})

	it('delivers to every subscriber of the same event', () => {
		const first = vi.fn()
		const second = vi.fn()
		const offFirst = onAppEvent('payment:verified', first)
		const offSecond = onAppEvent('payment:verified', second)

		emitAppEvent('payment:verified', { context: 'return' })

		expect(first).toHaveBeenCalledTimes(1)
		expect(second).toHaveBeenCalledTimes(1)

		offFirst()
		offSecond()
	})

	it('unsubscribes independently -- removing one subscriber leaves the other active', () => {
		const first = vi.fn()
		const second = vi.fn()
		const offFirst = onAppEvent('payment:verified', first)
		const offSecond = onAppEvent('payment:verified', second)

		offFirst()
		emitAppEvent('payment:verified', { context: 'purchase' })

		expect(first).not.toHaveBeenCalled()
		expect(second).toHaveBeenCalledTimes(1)

		offSecond()
	})

	it('isolates a throwing listener -- another subscriber still runs and the emitter does not throw', () => {
		const throwing = vi.fn(() => { throw new Error('boom') })
		const healthy = vi.fn()
		const offThrowing = onAppEvent('signing:completed', throwing)
		const offHealthy = onAppEvent('signing:completed', healthy)

		expect(() => emitAppEvent('signing:completed', {})).not.toThrow()
		expect(throwing).toHaveBeenCalledTimes(1)
		expect(healthy).toHaveBeenCalledTimes(1)
		expect(console.error).toHaveBeenCalled()

		offThrowing()
		offHealthy()
	})

	it('emitting with no subscribers is a no-op', () => {
		expect(() => emitAppEvent('reservation:created', {})).not.toThrow()
	})
})
