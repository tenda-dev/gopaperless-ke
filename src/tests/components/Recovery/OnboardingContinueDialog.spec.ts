/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * One-shot onboarding resume prompt: shown only when the server exposes a valid
 * signing path, never on signing screens, Continue navigates there exactly once
 * and latches so it cannot loop, and an invalid path can never become a redirect.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const VALID = '/apps/libresign/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'
const state = vi.hoisted(() => ({ resume: '/apps/libresign/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10', path: '/f/request' }))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => (key === 'onboarding_resume' ? state.resume : def),
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal<typeof import('@nextcloud/l10n')>()),
	t: (_app: string, text: string) => text,
}))
vi.mock('vue-router', () => ({
	useRoute: () => ({ path: state.path }),
}))

const LATCH_KEY = 'gopaperless.onboarding.resumed'

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
	const { default: OnboardingContinueDialog } = await import('../../../components/Recovery/OnboardingContinueDialog.vue')
	return mount(OnboardingContinueDialog, {
		global: { stubs: { NcDialog: NcDialogStub, NcButton: NcButtonStub } },
	})
}

let assign: ReturnType<typeof vi.fn>
const realLocation = window.location

beforeEach(() => {
	state.resume = VALID
	state.path = '/f/request'
	window.sessionStorage.clear()
	assign = vi.fn()
	Object.defineProperty(window, 'location', { value: { assign }, configurable: true, writable: true })
})

afterEach(() => {
	Object.defineProperty(window, 'location', { value: realLocation, configurable: true, writable: true })
	vi.restoreAllMocks()
})

describe('OnboardingContinueDialog.vue', () => {
	it('is not shown when the server exposes no resume path', async () => {
		state.resume = ''
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('is not shown (and never navigates) when the resume path is not a signing link', async () => {
		state.resume = '/apps/libresign/f/account'
		const w = await mountDialog()
		expect(w.find('.dlg').exists()).toBe(false)
	})

	it('rejects an off-origin resume value', async () => {
		state.resume = 'https://evil.example.com/apps/libresign/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('is shown with the agreed copy for a valid resume', async () => {
		const w = await mountDialog()
		expect(w.find('.dlg').attributes('data-name')).toBe('Continue signing?')
		expect(w.text()).toContain('You are all set. Would you like to return to the document you were signing?')
		expect(w.text()).toContain('Continue to document')
		expect(w.text()).toContain('Not now')
	})

	it('does not navigate until Continue is chosen', async () => {
		await mountDialog()
		expect(assign).not.toHaveBeenCalled()
	})

	it('Continue navigates to the signing path exactly once and latches', async () => {
		const w = await mountDialog()
		const buttons = w.findAll('button.btn')
		await buttons[buttons.length - 1].trigger('click')
		expect(assign).toHaveBeenCalledTimes(1)
		expect(assign).toHaveBeenCalledWith(VALID)
		expect(window.sessionStorage.getItem(LATCH_KEY)).toBe('1')
	})

	it('stays hidden once latched, so it cannot re-trigger on remount', async () => {
		window.sessionStorage.setItem(LATCH_KEY, '1')
		const w = await mountDialog()
		expect(w.find('.dlg').exists()).toBe(false)
	})

	it('Not now closes the dialog without navigating', async () => {
		const w = await mountDialog()
		await w.findAll('button.btn')[0].trigger('click')
		expect(w.find('.dlg').exists()).toBe(false)
		expect(assign).not.toHaveBeenCalled()
	})

	it('closing the dialog has the same effect as Not now', async () => {
		const w = await mountDialog()
		await w.find('.dlg-close').trigger('click')
		expect(w.find('.dlg').exists()).toBe(false)
	})

	it.each([
		['/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'],
		['/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10/pdf'],
		['/f/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10/pdf'],
		['/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'],
	])('is suppressed on signing route %s', async (path) => {
		state.path = path
		expect((await mountDialog()).find('.dlg').exists()).toBe(false)
	})

	it('still shows when reading storage throws', async () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
		expect((await mountDialog()).find('.dlg').exists()).toBe(true)
	})

	it('still navigates when writing storage throws', async () => {
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('quota') })
		const w = await mountDialog()
		await w.findAll('button.btn')[1].trigger('click')
		expect(assign).toHaveBeenCalledWith(VALID)
	})
})
