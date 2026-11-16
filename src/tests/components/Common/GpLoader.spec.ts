/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import GpLoader from '../../../components/Common/GpLoader.vue'

describe('GpLoader', () => {
	it('renders an accessible loading state by default', () => {
		const wrapper = mount(GpLoader)
		const root = wrapper.get('.gp-loader')
		expect(root.attributes('role')).toBe('status')
		expect(root.attributes('aria-busy')).toBe('true')
		// A visible spinner plus a screen-reader announcement.
		expect(wrapper.find('.gp-loader__spinner').exists()).toBe(true)
		expect(wrapper.find('.gp-loader__sr').text().length).toBeGreaterThan(0)
		// No error affordances while loading.
		expect(wrapper.find('.gp-loader__retry').exists()).toBe(false)
	})

	it('renders a recoverable error state with role=alert and a Retry control', () => {
		const wrapper = mount(GpLoader, { props: { state: 'error' } })
		const root = wrapper.get('.gp-loader')
		expect(root.attributes('role')).toBe('alert')
		expect(root.attributes('aria-busy')).toBe('false')
		expect(wrapper.find('.gp-loader__spinner').exists()).toBe(false)
		expect(wrapper.find('.gp-loader__retry').exists()).toBe(true)
	})

	it('emits retry when the Retry control is activated', async () => {
		const wrapper = mount(GpLoader, { props: { state: 'error' } })
		await wrapper.get('.gp-loader__retry').trigger('click')
		expect(wrapper.emitted('retry')).toHaveLength(1)
	})

	it('honours a custom label override', () => {
		const wrapper = mount(GpLoader, { props: { label: 'Preparing editor' } })
		expect(wrapper.find('.gp-loader__sr').text()).toBe('Preparing editor')
	})
})
