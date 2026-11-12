/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi, type MockedFunction } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((app: string, key: string, defaultValue: unknown) => defaultValue),
}))

vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: {
		name: 'NcEmptyContent',
		template: '<div class="nc-empty-content"><slot name="action" /><span class="title">{{ name }}</span><span class="description">{{ description }}</span></div>',
		props: ['name', 'description'],
	},
}))
vi.mock('@nextcloud/vue/components/NcIconSvgWrapper', () => ({
	default: { name: 'NcIconSvgWrapper', template: '<span />', props: ['path', 'size'] },
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', template: '<button class="nc-button"><slot /></button>', props: ['variant'] },
}))
vi.mock('@nextcloud/auth', () => ({
	getRequestToken: vi.fn(() => 'test-csrf-token'),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { name: 'NcNoteCard', template: '<div class="nc-note-card"><slot /></div>', props: ['type'] },
}))

import DefaultPageError from '../../views/DefaultPageError.vue'

describe('DefaultPageError', () => {
	let loadState: MockedFunction<typeof import('@nextcloud/initial-state').loadState>

	beforeEach(async () => {
		const { loadState: ls } = await import('@nextcloud/initial-state')
		loadState = ls as MockedFunction<typeof import('@nextcloud/initial-state').loadState>
		loadState.mockImplementation((_app, _key, defaultValue) => defaultValue)
	})

	it('shows "An error occurred" title and error message when errors are provided via initial state', () => {
		loadState.mockImplementation((app, key, defaultValue) => {
			if (app === 'libresign' && key === 'errors') {
				return [{ message: 'This document is not yours. Log out and use the sign link again.' }]
			}
			return defaultValue
		})

		const wrapper = mount(DefaultPageError)

		expect(wrapper.find('.title').text()).toBe('An error occurred')
		expect(wrapper.find('.description').text()).toBe('')
		expect(wrapper.find('.nc-note-card').text()).toContain('This document is not yours. Log out and use the sign link again.')
	})

	it('shows "Page not found" title and description when no errors are present', () => {
		const wrapper = mount(DefaultPageError)

		expect(wrapper.find('.title').text()).toBe('Page not found')
		expect(wrapper.find('.description').text()).toContain('Sorry but the page you are looking for')
		expect(wrapper.find('.nc-note-card').exists()).toBe(false)
	})

	it('shows error message from "error" key when "errors" array is empty', () => {
		loadState.mockImplementation((app, key, defaultValue) => {
			if (app === 'libresign' && key === 'errors') return []
			if (app === 'libresign' && key === 'error') return { message: 'Something went wrong' }
			return defaultValue
		})

		const wrapper = mount(DefaultPageError)

		expect(wrapper.find('.title').text()).toBe('An error occurred')
		expect(wrapper.find('.nc-note-card').text()).toContain('Something went wrong')
	})

	it('renders an optional server-provided "title" instead of the generic "An error occurred" heading, without changing how the error message itself is presented', () => {
		loadState.mockImplementation((app, key, defaultValue) => {
			if (app === 'libresign' && key === 'errors') {
				return [{ message: 'You do not have permission to view this document' }]
			}
			if (app === 'libresign' && key === 'title') {
				return 'Access denied'
			}
			return defaultValue
		})

		const wrapper = mount(DefaultPageError)

		expect(wrapper.find('.title').text()).toBe('Access denied')
		expect(wrapper.find('.description').text()).toBe('')
		expect(wrapper.find('.nc-note-card').text()).toContain('You do not have permission to view this document')
	})

	it('ignores an empty "title" and preserves the existing "Page not found" behaviour', () => {
		loadState.mockImplementation((app, key, defaultValue) => {
			if (app === 'libresign' && key === 'title') return ''
			return defaultValue
		})

		const wrapper = mount(DefaultPageError)

		expect(wrapper.find('.title').text()).toBe('Page not found')
		expect(wrapper.find('.description').text()).toContain('Sorry but the page you are looking for')
		expect(wrapper.find('.nc-note-card').exists()).toBe(false)
	})

	describe('switch account', () => {
		const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'
		const SWITCH_PATH = '/index.php/apps/libresign/account/switch'
		let submit: ReturnType<typeof vi.spyOn>

		const withErrors = (row: Record<string, unknown>, extra: Record<string, unknown> = {}) => {
			loadState.mockImplementation((app, key, defaultValue) => {
				if (app === 'libresign' && key === 'errors') return [row]
				if (app === 'libresign' && key in extra) return extra[key]
				return defaultValue
			})
		}

		beforeEach(() => {
			submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {})
			window.history.pushState({}, '', `/index.php/apps/libresign/p/sign/${UUID}`)
		})

		afterEach(() => {
			submit.mockRestore()
			document.body.innerHTML = ''
			document.cookie = 'libresign_resume=; path=/; max-age=0'
		})

		it('shows the masked address as text and the different-account heading', () => {
			withErrors({ message: 'This document was sent to a different account.', switchAccount: true, maskedEmail: 'br•••••@g•••••.com' })

			const wrapper = mount(DefaultPageError)

			expect(wrapper.find('.masked-email').text()).toBe('Sent to br•••••@g•••••.com')
			expect(wrapper.find('.title').text()).toBe('You\'re signed in to a different account')
			expect(wrapper.html()).not.toContain('bruno@gmail.com')
		})

		it('renders a masked address as inert text, never markup', () => {
			withErrors({ message: 'x', switchAccount: true, maskedEmail: '<img src=x onerror=alert(1)>' })

			const wrapper = mount(DefaultPageError)

			expect(wrapper.find('.masked-email img').exists()).toBe(false)
		})

		it('keeps a server-provided title over the different-account heading', () => {
			withErrors({ message: 'x', switchAccount: true, maskedEmail: 'br•••••@g•••••.com' }, { title: 'Access denied' })

			expect(mount(DefaultPageError).find('.title').text()).toBe('Access denied')
		})

		it('omits the masked line and keeps the generic heading when there is no maskedEmail', () => {
			withErrors({ message: 'This document was sent to a different account.', switchAccount: true })

			const wrapper = mount(DefaultPageError)

			expect(wrapper.find('.masked-email').exists()).toBe(false)
			expect(wrapper.find('.title').text()).toBe('An error occurred')
		})

		it('posts the uuid and request token to the server path as a top-level form', async () => {
			withErrors({ message: 'x', switchAccount: true, switchAccountPath: SWITCH_PATH, maskedEmail: 'br•••••@g•••••.com' })
			const wrapper = mount(DefaultPageError)

			await wrapper.find('.nc-button').trigger('click')

			const posted = document.querySelector('form') as HTMLFormElement
			expect(posted).not.toBeNull()
			expect(posted.method).toBe('post')
			expect(posted.getAttribute('action')).toBe(SWITCH_PATH)
			expect(Object.fromEntries(new FormData(posted).entries())).toEqual({ requesttoken: 'test-csrf-token', uuid: UUID })
			expect(submit).toHaveBeenCalledTimes(1)
			expect(document.cookie).not.toContain('libresign_resume')
			expect(posted.action + posted.innerHTML).not.toContain('@')
		})

		it.each([
			['the pdf route the router redirects the email link to', `/index.php/apps/libresign/p/sign/${UUID}/pdf`],
			['the sign-in route', `/index.php/apps/libresign/p/sign/${UUID}/sign-in`],
			['the renew-email route', `/index.php/apps/libresign/p/sign/${UUID}/renew/email`],
			['the internal pdf route', `/index.php/apps/libresign/f/sign/${UUID}/pdf`],
		])('posts to the recovery endpoint, not the legacy SLS, on %s', async (_label, path) => {
			window.history.pushState({}, '', path)
			withErrors({ message: 'x', switchAccount: true, switchAccountPath: SWITCH_PATH })
			const assign = vi.fn()
			vi.spyOn(window, 'location', 'get').mockReturnValue({ pathname: path, assign } as unknown as Location)
			const wrapper = mount(DefaultPageError)

			await wrapper.find('.nc-button').trigger('click')

			const posted = document.querySelector('form') as HTMLFormElement
			expect(posted).not.toBeNull()
			expect(posted.getAttribute('action')).toBe(SWITCH_PATH)
			expect(Object.fromEntries(new FormData(posted).entries())).toEqual({ requesttoken: 'test-csrf-token', uuid: UUID })
			expect(submit).toHaveBeenCalledTimes(1)
			expect(assign).not.toHaveBeenCalled()
			expect(document.cookie).not.toContain('libresign_resume')
			vi.restoreAllMocks()
		})

		it('keeps the legacy fallback on an unsupported signing sub-route even with a switchAccountPath', async () => {
			const path = `/index.php/apps/libresign/p/sign/${UUID}/unrelated`
			window.history.pushState({}, '', path)
			withErrors({ message: 'x', switchAccount: true, switchAccountPath: SWITCH_PATH })
			const assign = vi.fn()
			vi.spyOn(window, 'location', 'get').mockReturnValue({ pathname: path, assign } as unknown as Location)
			const wrapper = mount(DefaultPageError)

			await wrapper.find('.nc-button').trigger('click')

			expect(submit).not.toHaveBeenCalled()
			expect(assign).toHaveBeenCalledWith(expect.stringContaining('/apps/user_oidc/sls'))
			vi.restoreAllMocks()
		})

		it('keeps the legacy cookie plus SLS behaviour when the server gave no switchAccountPath', async () => {
			withErrors({ message: 'x', switchAccount: true })
			const assign = vi.fn()
			const path = window.location.pathname
			vi.spyOn(window, 'location', 'get').mockReturnValue({ pathname: path, assign } as unknown as Location)
			const wrapper = mount(DefaultPageError)

			await wrapper.find('.nc-button').trigger('click')

			expect(submit).not.toHaveBeenCalled()
			expect(document.cookie).toContain(`libresign_resume=${encodeURIComponent(path)}`)
			expect(assign).toHaveBeenCalledWith(expect.stringContaining('/apps/user_oidc/sls'))
			vi.restoreAllMocks()
		})

		it('falls back to the single-logout path when no uuid can be parsed', async () => {
			window.history.pushState({}, '', '/index.php/apps/libresign/p/sign/not-a-uuid')
			withErrors({ message: 'x', switchAccount: true, switchAccountPath: SWITCH_PATH })
			const assign = vi.fn()
			vi.spyOn(window, 'location', 'get').mockReturnValue({ pathname: window.location.pathname, assign } as unknown as Location)
			const wrapper = mount(DefaultPageError)

			await wrapper.find('.nc-button').trigger('click')

			expect(submit).not.toHaveBeenCalled()
			expect(assign).toHaveBeenCalledWith(expect.stringContaining('/apps/user_oidc/sls'))
			vi.restoreAllMocks()
		})
	})
})
