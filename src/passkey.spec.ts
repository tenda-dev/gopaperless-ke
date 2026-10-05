/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))

const MIMI = 'https://mimi.example'

describe('MIMI passkey frame', () => {
	const options = { challenge: 'AQI', rpId: 'mimi.example' }
	let mimi: { postMessage: ReturnType<typeof vi.fn> }
	let assign: ReturnType<typeof vi.spyOn>

	const from = (data: unknown, origin = MIMI, source: unknown = mimi) =>
		window.dispatchEvent(new MessageEvent('message', { data, origin, source: source as Window }))

	beforeEach(() => {
		vi.resetModules()
		post.mockReset()
		post.mockImplementation(async (url: string) => {
			if (url.endsWith('/options')) {
				return { data: { options } }
			}
			return { data: { verified: true } }
		})
		vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
		assign = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
		document.body.innerHTML = `<div class="gp-passkey" data-return-to="/apps/libresign/" data-logout-url="/logout?requesttoken=t">
			<iframe id="gp-passkey-frame"></iframe>
		</div>`
		const frame = document.querySelector<HTMLIFrameElement>('#gp-passkey-frame')!
		mimi = { postMessage: vi.fn() }
		Object.defineProperty(frame, 'src', { value: `${MIMI}/passkey/frame?client_id=gopaperless` })
		Object.defineProperty(frame, 'contentWindow', { value: mimi })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('hands MIMI one challenge once the frame is ready', async () => {
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_READY' })
		from({ type: 'MIMI_PASSKEY_READY' })
		await flushPromises()
		expect(post).toHaveBeenCalledTimes(1)
		expect(mimi.postMessage).toHaveBeenCalledTimes(1)
		expect(mimi.postMessage).toHaveBeenCalledWith({ type: 'MIMI_PASSKEY_REQUEST', options, retry: undefined }, MIMI)
	})

	it('ignores messages from any other origin or window', async () => {
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_READY' }, 'https://evil.example')
		from({ type: 'MIMI_PASSKEY_READY' }, MIMI, window)
		from({ type: 'MIMI_PASSKEY_CANCEL' }, 'https://evil.example')
		await flushPromises()
		expect(post).not.toHaveBeenCalled()
		expect(assign).not.toHaveBeenCalled()
	})

	it('opens GoPaperless once the server accepts the passkey', async () => {
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_RESULT', response: { id: 'cred' } })
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/apps/libresign/securysign/passkey/verify', { response: { id: 'cred' } })
		expect(assign).toHaveBeenCalledWith('/apps/libresign/')
	})

	it('asks again with a fresh challenge when the server refuses the passkey', async () => {
		post.mockImplementation(async (url: string) => {
			if (url.endsWith('/verify')) {
				throw Object.assign(new Error('403'), { response: { status: 403 } })
			}
			return { data: { options } }
		})
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_RESULT', response: { id: 'other' } })
		await flushPromises()
		expect(mimi.postMessage).toHaveBeenCalledWith({ type: 'MIMI_PASSKEY_REQUEST', options, retry: 'rejected' }, MIMI)
		expect(assign).not.toHaveBeenCalled()
	})

	it('signs out when the person cancels in the frame', async () => {
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_CANCEL' })
		expect(assign).toHaveBeenCalledWith('/logout?requesttoken=t')
	})

	it('follows the server when there is no passkey yet or MIMI is down', async () => {
		post.mockResolvedValue({ data: { redirect: '/apps/libresign/securysign/onboard' } })
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_READY' })
		await flushPromises()
		expect(assign).toHaveBeenCalledWith('/apps/libresign/securysign/onboard')
		expect(mimi.postMessage).not.toHaveBeenCalled()
	})

	it('waits until a background tab is seen before asking', async () => {
		const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
		await import('./passkey.ts')
		from({ type: 'MIMI_PASSKEY_READY' })
		await flushPromises()
		expect(post).not.toHaveBeenCalled()
		visibility.mockReturnValue('visible')
		document.dispatchEvent(new Event('visibilitychange'))
		await flushPromises()
		expect(mimi.postMessage).toHaveBeenCalledTimes(1)
	})
})
