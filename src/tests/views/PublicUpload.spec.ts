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

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
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
		loadStateMock.mockReset()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
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

	it('navigates to login when get-started is emitted and no OIDC provider is configured', () => {
		const hrefSetter = vi.fn()
		Object.defineProperty(window.location, 'href', {
			configurable: true,
			set: hrefSetter,
		})

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHero).vm.$emit('get-started')

		expect(hrefSetter).toHaveBeenCalledWith('/login?redirect_url=%2Fapps%2Flibresign%2Ff%2Frequest')
	})

	it('navigates to the configured OIDC login URL when get-started is emitted', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'public_upload_oidc_login_url') {
				return '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest'
			}
			return fallback
		})

		const hrefSetter = vi.fn()
		Object.defineProperty(window.location, 'href', {
			configurable: true,
			set: hrefSetter,
		})

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHero).vm.$emit('get-started')

		expect(hrefSetter).toHaveBeenCalledWith('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest')
	})

	it('navigates to the configured OIDC login URL when sign-in is emitted', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'public_upload_oidc_login_url') {
				return '/apps/user_oidc/login/1?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest'
			}
			return fallback
		})

		const hrefSetter = vi.fn()
		Object.defineProperty(window.location, 'href', {
			configurable: true,
			set: hrefSetter,
		})

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHeader).vm.$emit('sign-in')

		expect(hrefSetter).toHaveBeenCalledWith('/apps/user_oidc/login/1?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest')
	})
})
