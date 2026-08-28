/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'

import PublicUpload from '../../views/PublicUpload.vue'
import PublicUploadEcosystem from '../../components/PublicUploadEcosystem.vue'
import PublicUploadHeader from '../../components/PublicUploadHeader.vue'
import PublicUploadHero from '../../components/PublicUploadHero.vue'
import PublicUploadStages from '../../components/PublicUploadStages.vue'

const replaceMock = vi.fn()

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => null),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('vue-router', async () => {
	const actual = await vi.importActual<typeof import('vue-router')>('vue-router')
	return {
		...actual,
		useRouter: () => ({
			replace: replaceMock,
		}),
	}
})

describe('PublicUpload', () => {
	beforeEach(() => {
		replaceMock.mockReset()
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: vi.fn().mockImplementation((query: string) => ({
				matches: query === '(prefers-reduced-motion:reduce)',
				media: query,
			})),
		})
	})

	it('renders the landing sections for anonymous visitors', () => {
		const wrapper = shallowMount(PublicUpload)

		expect(wrapper.findComponent(PublicUploadHeader).exists()).toBe(true)
		expect(wrapper.findComponent(PublicUploadHero).exists()).toBe(true)
		expect(wrapper.findComponent(PublicUploadStages).exists()).toBe(true)
		expect(wrapper.findComponent(PublicUploadEcosystem).exists()).toBe(true)
	})

	it('navigates to login when get-started is emitted', () => {
		const hrefSetter = vi.fn()
		Object.defineProperty(window.location, 'href', {
			configurable: true,
			set: hrefSetter,
		})

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHero).vm.$emit('get-started')

		expect(hrefSetter).toHaveBeenCalledWith('/login?redirect_url=%2Fapps%2Flibresign%2Ff%2Frequest')
	})
})
