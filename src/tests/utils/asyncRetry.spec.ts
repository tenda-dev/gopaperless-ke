/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'

import { once } from '../../utils/asyncRetry'

describe('once', () => {
	it('reuses a single import promise across calls', () => {
		const loader = vi.fn(() => Promise.resolve('x'))
		const wrapped = once(loader)
		expect(wrapped()).toBe(wrapped())
		expect(loader).toHaveBeenCalledOnce()
	})

	it('clears the cached promise on rejection so a later call retries', async () => {
		let n = 0
		const loader = vi.fn(() => {
			n++
			return n === 1 ? Promise.reject(new Error('boom')) : Promise.resolve('ok')
		})
		const wrapped = once(loader)
		await expect(wrapped()).rejects.toThrow('boom')
		await expect(wrapped()).resolves.toBe('ok')
		expect(loader).toHaveBeenCalledTimes(2)
	})
})
