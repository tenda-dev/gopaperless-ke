/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { ComputedRef } from 'vue'

export type MotionKey = string | number | null | undefined

export interface MotionGuard {
	/** Whether a motion key was provided. */
	optedIn: ComputedRef<boolean>
	/** Whether transitions are currently allowed for the active context. */
	motionEnabled: ComputedRef<boolean>
}

/**
 * Temporarily suppresses transitions when the keyed context changes.
 *
 * Cached document switches patch sidebar components in place. The synchronous
 * watcher disables motion before the new document state is rendered, then
 * restores it after two animation frames.
 *
 * Motion is opt-in. Without a key, consumers retain their existing behaviour.
 */
export function useMotionGuard(key: () => MotionKey): MotionGuard {
	const optedIn = computed(() => key() !== undefined && key() !== null)
	const settled = ref(true)
	let frameA = 0
	let frameB = 0

	function cancelFrames(): void {
		if (frameA) {
			cancelAnimationFrame(frameA)
			frameA = 0
		}
		if (frameB) {
			cancelAnimationFrame(frameB)
			frameB = 0
		}
	}

	watch(key, () => {
		settled.value = false
		cancelFrames()
		frameA = requestAnimationFrame(() => {
			frameA = 0
			frameB = requestAnimationFrame(() => {
				frameB = 0
				settled.value = true
			})
		})
	}, { flush: 'sync' })

	onBeforeUnmount(cancelFrames)

	return {
		optedIn,
		motionEnabled: computed(() => optedIn.value && settled.value),
	}
}
