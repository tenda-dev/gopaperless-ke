/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, ref } from 'vue'

import { useDialogCoordinatorStore } from '../../store/dialogCoordinator'

function setup() {
	setActivePinia(createPinia())
	const store = useDialogCoordinatorStore()
	store.setPrerequisite('accepted')
	return store
}

function reg(store: ReturnType<typeof setup>, id: string, priority: number, eligible = false) {
	const e = ref(eligible)
	store.register({ id, priority, eligible: e })
	return e
}

// Arbitration runs once per tick.
const settle = () => nextTick()

describe('dialogCoordinator', () => {
	let store: ReturnType<typeof setup>
	beforeEach(() => {
		store = setup()
	})

	it('has no owner when nothing is eligible', async () => {
		reg(store, 'a', 10)
		await settle()
		expect(store.currentOwnerId).toBeNull()
		expect(store.canPresent('a')).toBe(false)
	})

	it('a single eligible participant becomes owner', async () => {
		reg(store, 'a', 10, true)
		await settle()
		expect(store.currentOwnerId).toBe('a')
		expect(store.canPresent('a')).toBe(true)
	})

	it('picks the lowest priority number among several eligible', async () => {
		reg(store, 'cert-gate', 20, true)
		reg(store, 'pw-claim', 30, true)
		reg(store, 'recovery', 10, true)
		await settle()
		expect(store.currentOwnerId).toBe('recovery')
	})

	it('is independent of registration order (same tick)', async () => {
		const s1 = setup()
		reg(s1, 'x', 30, true)
		reg(s1, 'y', 10, true)
		await settle()
		const s2 = setup()
		reg(s2, 'y', 10, true)
		reg(s2, 'x', 30, true)
		await settle()
		expect(s1.currentOwnerId).toBe('y')
		expect(s2.currentOwnerId).toBe('y')
	})

	it('breaks equal-priority ties by id, lexicographically', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		reg(store, 'b', 5, true)
		reg(store, 'a', 5, true)
		await settle()
		expect(store.currentOwnerId).toBe('a')
	})

	it('does not preempt: a higher-priority dialog waits for the owner to release', async () => {
		const low = reg(store, 'pw-claim', 30, true)
		const high = reg(store, 'recovery', 10, false)
		await settle()
		expect(store.currentOwnerId).toBe('pw-claim')
		high.value = true
		await settle()
		expect(store.currentOwnerId).toBe('pw-claim')
		expect(store.canPresent('recovery')).toBe(false)
		low.value = false
		await settle()
		expect(store.currentOwnerId).toBe('recovery')
	})

	it('releases and re-arbitrates when the owner becomes ineligible', async () => {
		const a = reg(store, 'a', 10, true)
		reg(store, 'b', 20, true)
		await settle()
		expect(store.currentOwnerId).toBe('a')
		a.value = false
		// Never stale: the old owner may not present even before re-arbitration.
		expect(store.canPresent('a')).toBe(false)
		await settle()
		expect(store.currentOwnerId).toBe('b')
	})

	it('releases when the owner unregisters', async () => {
		reg(store, 'a', 10, true)
		reg(store, 'b', 20, true)
		await settle()
		store.unregister('a')
		expect(store.canPresent('a')).toBe(false)
		await settle()
		expect(store.currentOwnerId).toBe('b')
		store.unregister('b')
		await settle()
		expect(store.currentOwnerId).toBeNull()
	})

	it('register is idempotent by id', async () => {
		const first = reg(store, 'a', 10, true)
		const second = ref(false)
		store.register({ id: 'a', priority: 10, eligible: second })
		await settle()
		first.value = false
		await settle()
		expect(store.currentOwnerId).toBeNull()
	})

	it('never allows two simultaneous owners', async () => {
		reg(store, 'a', 10, true)
		reg(store, 'b', 20, true)
		await settle()
		expect(['a', 'b'].filter(id => store.canPresent(id))).toEqual(['a'])
	})

	describe('prerequisite', () => {
		it.each(['pending', 'unknown'] as const)('%s blocks all ownership', async (state) => {
			reg(store, 'a', 10, true)
			await settle()
			store.setPrerequisite(state)
			expect(store.allowAutoDialogs).toBe(false)
			expect(store.canPresent('a')).toBe(false)
			await settle()
			expect(store.currentOwnerId).toBeNull()
		})

		it('starts closed (unknown) before it is resolved', async () => {
			setActivePinia(createPinia())
			const fresh = useDialogCoordinatorStore()
			reg(fresh, 'a', 10, true)
			await settle()
			expect(fresh.currentOwnerId).toBeNull()
			expect(fresh.canPresent('a')).toBe(false)
		})

		it.each(['accepted', 'not-enabled'] as const)('%s lets arbitration resume', async (state) => {
			store.setPrerequisite('pending')
			reg(store, 'a', 10, true)
			await settle()
			expect(store.currentOwnerId).toBeNull()
			store.setPrerequisite(state)
			await settle()
			expect(store.currentOwnerId).toBe('a')
		})
	})

	describe('suspension tokens', () => {
		it('suspends and resumes', async () => {
			reg(store, 'a', 10, true)
			await settle()
			store.suspend('t1')
			expect(store.canPresent('a')).toBe(false)
			await settle()
			expect(store.currentOwnerId).toBeNull()
			store.resume('t1')
			await settle()
			expect(store.currentOwnerId).toBe('a')
		})

		it('stays suspended while any other token remains (overlap)', async () => {
			reg(store, 'a', 10, true)
			store.suspend('t1')
			store.suspend('t2')
			store.resume('t1')
			await settle()
			expect(store.allowAutoDialogs).toBe(false)
			expect(store.currentOwnerId).toBeNull()
			store.resume('t2')
			await settle()
			expect(store.currentOwnerId).toBe('a')
		})

		it('is idempotent per token and tolerates unknown resume', async () => {
			reg(store, 'a', 10, true)
			store.suspend('t1')
			store.suspend('t1')
			store.resume('nope')
			expect(store.allowAutoDialogs).toBe(false)
			store.resume('t1')
			expect(store.allowAutoDialogs).toBe(true)
		})
	})

	it('leaves no stale ownership after everything unregisters', async () => {
		reg(store, 'a', 10, true)
		await settle()
		store.unregister('a')
		await settle()
		expect(store.currentOwnerId).toBeNull()
		expect(store.canPresent('a')).toBe(false)
	})
})
