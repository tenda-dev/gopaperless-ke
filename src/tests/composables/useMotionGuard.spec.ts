/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'

import { useMotionGuard } from '../../composables/useMotionGuard'
import type { MotionKey } from '../../composables/useMotionGuard'

describe('useMotionGuard', () => {
	let frames: Array<() => void>

	function flushFrame() {
		const queued = frames
		frames = []
		queued.forEach((cb) => cb())
	}

	beforeEach(() => {
		frames = []
		vi.stubGlobal('requestAnimationFrame', (cb: () => void) => {
			frames.push(cb)
			return frames.length
		})
		vi.stubGlobal('cancelAnimationFrame', () => {
			frames = []
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	function setup(initial: MotionKey) {
		const key = ref<MotionKey>(initial)
		let guard!: ReturnType<typeof useMotionGuard>
		mount(defineComponent({
			setup() {
				guard = useMotionGuard(() => key.value)
				return () => h('div')
			},
		}))
		return { key, guard }
	}

	it('is not opted in, and never enabled, without a key', async () => {
		const { key, guard } = setup(undefined)
		expect(guard.optedIn.value).toBe(false)
		expect(guard.motionEnabled.value).toBe(false)

		key.value = 7
		await nextTick()
		flushFrame()
		flushFrame()
		expect(guard.optedIn.value).toBe(true)
		expect(guard.motionEnabled.value).toBe(true)
	})

	it('treats null like no key', () => {
		const { guard } = setup(null)
		expect(guard.optedIn.value).toBe(false)
		expect(guard.motionEnabled.value).toBe(false)
	})

	it('is enabled on mount when a key is given (nothing animates on mount: that is the consumer\'s DOM state)', () => {
		const { guard } = setup(5)
		expect(guard.motionEnabled.value).toBe(true)
	})

	it('is disabled synchronously on key change and re-enabled only after two frames', () => {
		const { key, guard } = setup(5)

		key.value = 6
		// no await: the watcher is synchronous, so the next render already sees false
		expect(guard.motionEnabled.value).toBe(false)

		flushFrame()
		expect(guard.motionEnabled.value).toBe(false)

		flushFrame()
		expect(guard.motionEnabled.value).toBe(true)
	})

	it('restarts the window on rapid successive switches', () => {
		const { key, guard } = setup(1)

		key.value = 2
		flushFrame()
		key.value = 3
		expect(guard.motionEnabled.value).toBe(false)

		flushFrame()
		expect(guard.motionEnabled.value).toBe(false)
		flushFrame()
		expect(guard.motionEnabled.value).toBe(true)
	})
})
