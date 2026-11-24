/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CertificateGate participates in dialog coordination as 'cert-gate' (priority 20):
 * its one automatic nudge opens only when the coordinator grants presentation,
 * is spent once the modal closes, and never blocks the persistent banner.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, nextTick, reactive, ref } from 'vue'

const ctx = vi.hoisted(() => ({ certRequired: undefined as unknown as { value: boolean } }))
const gate = vi.hoisted(() => ({ canPresent: undefined as unknown as { value: boolean }, opts: null as null | { id: string, priority: number, eligible: { value: boolean } } }))

vi.mock('@/store/userContext', async () => {
	const { ref } = await import('vue')
	ctx.certRequired = ref(false)
	return { useUserContextStore: () => ({ get certRequired() { return ctx.certRequired.value } }) }
})
vi.mock('@/composables/useManagedDialog', async () => {
	const { ref, computed } = await import('vue')
	const allow = ref(false)
	gate.canPresent = allow
	return {
		useManagedDialog: (opts: { id: string, priority: number, eligible: { value: boolean } }) => {
			gate.opts = opts
			return { canPresent: computed(() => allow.value) }
		},
	}
})

import CertificateGate from '../../../components/Certificate/CertificateGate.vue'

const Payment = {
	props: ['open'],
	emits: ['update:open', 'paid'],
	template: '<div class="payment" :data-open="String(open)"><button class="close" @click="$emit(\'update:open\', false)" /><button class="paid" @click="$emit(\'paid\')" /></div>',
}
const Banner = {
	emits: ['activate'],
	template: '<button class="banner" @click="$emit(\'activate\')" />',
}

function mountGate() {
	return mount(CertificateGate, { global: { stubs: { CertificatePayment: Payment, CertificateBanner: Banner } } })
}

const isOpen = (w: ReturnType<typeof mountGate>) => w.find('.payment').attributes('data-open') === 'true'

describe('CertificateGate coordination', () => {
	beforeEach(() => {
		ctx.certRequired.value = true
		gate.canPresent.value = false
	})

	it('registers as "cert-gate" with priority 20', () => {
		mountGate()
		expect(gate.opts).toMatchObject({ id: 'cert-gate', priority: 20 })
	})

	it('does not auto-open while the coordinator withholds presentation, but shows the banner', async () => {
		const w = mountGate()
		await flushPromises()
		expect(isOpen(w)).toBe(false)
		expect(w.find('.banner').exists()).toBe(true)
	})

	it('auto-opens once when the coordinator grants presentation', async () => {
		const w = mountGate()
		gate.canPresent.value = true
		await flushPromises()
		expect(isOpen(w)).toBe(true)
		expect(w.find('.banner').exists()).toBe(false)
	})

	it('is eligible only while a certificate is required and the nudge is unspent', async () => {
		const w = mountGate()
		expect(gate.opts!.eligible.value).toBe(true)
		gate.canPresent.value = true
		await flushPromises()
		// Still eligible (so it keeps ownership) while the modal is open.
		expect(gate.opts!.eligible.value).toBe(true)
		await w.find('.close').trigger('click')
		expect(gate.opts!.eligible.value).toBe(false)
	})

	it('dismissal spends the nudge: falls back to the banner and does not reopen', async () => {
		const w = mountGate()
		gate.canPresent.value = true
		await flushPromises()
		await w.find('.close').trigger('click')
		gate.canPresent.value = false
		await nextTick()
		gate.canPresent.value = true
		await flushPromises()
		expect(isOpen(w)).toBe(false)
		expect(w.find('.banner').exists()).toBe(true)
	})

	it('the banner still opens the modal manually without the coordinator', async () => {
		const w = mountGate()
		await w.find('.banner').trigger('click')
		expect(isOpen(w)).toBe(true)
	})

	it('paying spends the nudge', async () => {
		const w = mountGate()
		gate.canPresent.value = true
		await flushPromises()
		await w.find('.paid').trigger('click')
		expect(gate.opts!.eligible.value).toBe(false)
	})

	it('becoming not-required makes it ineligible and collapses everything', async () => {
		const w = mountGate()
		gate.canPresent.value = true
		await flushPromises()
		ctx.certRequired.value = false
		await flushPromises()
		expect(gate.opts!.eligible.value).toBe(false)
		expect(w.find('.payment').exists()).toBe(false)
		expect(w.find('.banner').exists()).toBe(false)
	})
})
