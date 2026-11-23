/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Post-login pending-recovery prompt: shown only when the server says a
 * recovery is pending, never on signing screens, Continue goes to the fixed
 * completion endpoint, and dismissal is a per-tab sessionStorage flag that
 * survives storage failures and never touches the recovery cookie.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const state = vi.hoisted(() => ({ pending: true as unknown, path: '/f/request' }))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => (key === 'recovery_pending' ? state.pending : def),
}))
// Partial mock: the real NcDialog import chain needs the rest of l10n.
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof import('@nextcloud/l10n')>()),
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => '/index.php' + path,
}))
vi.mock('vue-router', () => ({
	useRoute: () => ({ path: state.path }),
}))

const DISMISSED_KEY = 'gopaperless.recovery.dismissed'

const NcDialogStub = {
	props: ['name'],
	emits: ['update:open'],
	template: '<div class="dlg" :data-name="name"><slot /><button class="dlg-close" @click="$emit(\'update:open\', false)">x</button></div>',
}
const NcButtonStub = {
	emits: ['click'],
	template: '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
}

async function mountDialog() {
	vi.resetModules()
	const { default: RecoveryContinueDialog } = await import('../../../components/Recovery/RecoveryContinueDialog.vue')
	return mount(RecoveryContinueDialog, {
		global: { stubs: { NcDialog: NcDialogStub, NcButton: NcButtonStub } },
	})
}

let assign: ReturnType<typeof vi.fn>
const realLocation = window.location

beforeEach(() => {
	state.pending = true
	state.path = '/f/request'
	window.sessionStorage.clear()
	assign = vi.fn()
	Object.defineProperty(window, 'location', { value: { assign }, configurable: true, writable: true })
})

afterEach(() => {
	Object.defineProperty(window, 'location', { value: realLocation, configurable: true, writable: true })
	vi.restoreAllMocks()
})

describe('RecoveryContinueDialog.vue', () => {
	it('is not shown when the server reports nothing pending', async () => {
		state.pending = false
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('treats anything but boolean true as not pending', async () => {
		state.pending = 'yes'
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('is shown with the agreed copy when a recovery is pending', async () => {
		const w = await mountDialog()
		expect(w.find('.dlg').attributes('data-name')).toBe('Continue signing?')
		expect(w.text()).toContain('You have successfully signed in. Would you like to return to the document you were trying to sign?')
		expect(w.text()).toContain('Continue to document')
		expect(w.text()).toContain('Not now')
	})

	it('does not navigate until Continue is chosen', async () => {
		await mountDialog()
		expect(assign).not.toHaveBeenCalled()
	})

	it('Continue goes to the fixed completion endpoint and nothing else', async () => {
		const w = await mountDialog()
		const buttons = w.findAll('button.btn')
		await buttons[buttons.length - 1].trigger('click')
		expect(assign).toHaveBeenCalledTimes(1)
		expect(assign).toHaveBeenCalledWith('/index.php/apps/libresign/account/recover/complete')
	})

	it('Not now closes the dialog and sets the per-tab flag without navigating', async () => {
		const w = await mountDialog()
		await w.findAll('button.btn')[0].trigger('click')
		expect(w.find('.dlg').exists()).toBe(false)
		expect(window.sessionStorage.getItem(DISMISSED_KEY)).toBe('1')
		expect(assign).not.toHaveBeenCalled()
	})

	it('closing the dialog has the same effect as Not now', async () => {
		const w = await mountDialog()
		await w.find('.dlg-close').trigger('click')
		expect(w.find('.dlg').exists()).toBe(false)
		expect(window.sessionStorage.getItem(DISMISSED_KEY)).toBe('1')
	})

	it('stays hidden after a refresh in the same tab', async () => {
		window.sessionStorage.setItem(DISMISSED_KEY, '1')
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('never reads, writes or clears any cookie, on mount or on dismissal', async () => {
		const access: string[] = []
		Object.defineProperty(document, 'cookie', {
			configurable: true,
			get: () => { access.push('get'); return 'gp_recover=sentinel' },
			set: () => { access.push('set') },
		})
		try {
			const w = await mountDialog()
			await w.findAll('button.btn')[0].trigger('click')
			expect(access).toEqual([])
		} finally {
			delete (document as unknown as Record<string, unknown>).cookie
		}
	})

	it('still shows when reading storage throws', async () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
		expect((await mountDialog()).find('.dlg').exists()).toBe(true)
	})

	it('still closes when writing storage throws', async () => {
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('quota') })
		const w = await mountDialog()
		await w.findAll('button.btn')[0].trigger('click')
		expect(w.find('.dlg').exists()).toBe(false)
	})

	it.each([
		['/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'],
		['/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10/pdf'],
		['/p/upload'],
		['/f/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10/pdf'],
		['/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'],
	])('is suppressed on %s', async (path) => {
		state.path = path
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it.each([
		['/f/request'],
		['/f/filelist/sign'],
		['/f/account'],
	])('is shown on the app page %s', async (path) => {
		state.path = path
		expect((await mountDialog()).find('.dlg').exists()).toBe(true)
	})
})
