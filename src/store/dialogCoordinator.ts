/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, shallowReactive, watchEffect } from 'vue'
import type { Ref } from 'vue'
import { defineStore } from 'pinia'

import type { ToSPrereq } from '@/composables/useTermsPrerequisite'

export interface ManagedDialogOptions {
	/** 'recovery' | 'onboarding' | 'cert-gate' | 'pw-claim' */
	id: string
	/** Unique integer; lower number = higher priority. */
	priority: number
	/** Business eligibility AND NOT (dismissed | completed | invalidated). */
	eligible: Ref<boolean>
}

interface Participant {
	priority: number
	isEligible: () => boolean
}

/**
 * Coordinates presentation ownership among automatic dialogs.
 *
 * Participants retain their own eligibility, rendering, and business logic;
 * this store grants presentation permission to one eligible participant.
 * Ownership is retained while the current participant remains eligible.
 * Otherwise, the highest-priority eligible participant wins, with ID ordering
 * providing deterministic tie-breaking. There is no queue or preemption.
 */
export const useDialogCoordinatorStore = defineStore('dialogCoordinator', () => {
	const participants = shallowReactive(new Map<string, Participant>())
	const suspenders = shallowReactive(new Set<string>())
	const prerequisite = ref<ToSPrereq>('unknown')
	const currentOwnerId = ref<string | null>(null)

	const allowAutoDialogs = computed(() =>
		(prerequisite.value === 'accepted' || prerequisite.value === 'not-enabled')
		&& suspenders.size === 0,
	)

	// Re-arbitrate when eligibility, prerequisite state, suspension, or
	// registration changes. Retain a valid owner; otherwise select the next
	// eligible participant deterministically. canPresent() independently verifies
	// ownership and live eligibility before granting presentation permission.
	watchEffect(() => {
		if (!allowAutoDialogs.value) {
			currentOwnerId.value = null
			return
		}

		const owner = currentOwnerId.value
		if (owner !== null && participants.get(owner)?.isEligible()) {
			// Retain ownership while the current participant remains eligible.
			return
		}

		let best: { id: string, priority: number } | null = null
		for (const [id, p] of participants) {
			if (!p.isEligible()) {
				continue
			}
			if (best === null || p.priority < best.priority || (p.priority === best.priority && id < best.id)) {
				best = { id, priority: p.priority }
			}
		}
		currentOwnerId.value = best?.id ?? null
	})

	function register(options: ManagedDialogOptions): void {
		if (participants.has(options.id)) {
			return
		}
		if (import.meta.env?.DEV) {
			for (const [id, p] of participants) {
				if (p.priority === options.priority) {
					console.warn(`[dialogCoordinator] priority ${options.priority} of "${options.id}" collides with "${id}"; id order breaks the tie`)
				}
			}
		}
		const { eligible } = options
		participants.set(options.id, { priority: options.priority, isEligible: () => eligible.value })
	}

	function unregister(id: string): void {
		participants.delete(id)
		if (currentOwnerId.value === id) {
			currentOwnerId.value = null
		}
	}

	function canPresent(id: string): boolean {
		return allowAutoDialogs.value
			&& currentOwnerId.value === id
			&& participants.get(id)?.isEligible() === true
	}

	function setPrerequisite(state: ToSPrereq): void {
		prerequisite.value = state
		// Retained diagnostic for the server-side ToS state used by the dialog gate.
		console.debug('[gp-dialogs] coordinator prerequisite', state, '→ allowAutoDialogs', allowAutoDialogs.value)
	}

	/** Ownership-based suspension: the token identifies the suspender. */
	function suspend(token: string): void {
		suspenders.add(token)
	}

	function resume(token: string): void {
		suspenders.delete(token)
	}

	return {
		currentOwnerId,
		prerequisite,
		allowAutoDialogs,
		register,
		unregister,
		canPresent,
		setPrerequisite,
		suspend,
		resume,
	}
})
