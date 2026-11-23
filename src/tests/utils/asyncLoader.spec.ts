/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import { asyncLoader } from '../../utils/asyncLoader'

const Loaded = defineComponent({
	name: 'Loaded',
	emits: ['save'],
	setup(_props, { emit }) {
		return () => h('button', { class: 'loaded', onClick: () => emit('save', 'ok') }, 'loaded')
	},
})

/** Drain the chained retry microtasks (initial + bounded retries). */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await flushPromises()
	}
}

describe('asyncLoader', () => {
	it('resolves and renders the wrapped component, forwarding listeners', async () => {
		const onSave = vi.fn()
		const Comp = asyncLoader(() => Promise.resolve({ __esModule: true, default: Loaded }))
		const wrapper = mount(Comp, { attrs: { onSave } })
		await settle()
		expect(wrapper.find('.loaded').exists()).toBe(true)
		await wrapper.get('.loaded').trigger('click')
		expect(onSave).toHaveBeenCalledWith('ok')
	})

	it('shows a recoverable error after the retry budget is exhausted, then recovers on Retry', async () => {
		let calls = 0
		const loader = vi.fn(() => {
			calls++
			return calls <= 3
				? Promise.reject(new Error('chunk load failure'))
				: Promise.resolve({ __esModule: true, default: Loaded })
		})
		const Comp = asyncLoader(loader)
		const wrapper = mount(Comp)
		await settle()
		// Initial attempt + 2 bounded retries, all failed -> recoverable error UI.
		expect(calls).toBe(3)
		expect(wrapper.find('.gp-loader--error').exists()).toBe(true)
		expect(wrapper.find('.gp-loader__retry').exists()).toBe(true)
		expect(wrapper.find('.loaded').exists()).toBe(false)

		// Retry re-attempts the fetch from scratch; this time it succeeds.
		await wrapper.get('.gp-loader__retry').trigger('click')
		await settle()
		expect(wrapper.find('.loaded').exists()).toBe(true)
		expect(wrapper.find('.gp-loader--error').exists()).toBe(false)
	})
})
