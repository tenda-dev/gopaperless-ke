/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Claim-privacy contract for the authenticated claim prompt: the masked hint
 * (never the full email) is the only requester-email data shown, an email
 * input is required only when the server says verification is needed, the
 * typed value is what gets sent to claimWorkflow(), and a failed verification
 * surfaces the server's own non-revealing message rather than anything
 * reconstructed client-side.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { createL10nMock } from '../../testHelpers/l10n.js'

const getClaimStatusMock = vi.fn()
const claimWorkflowMock = vi.fn()

// Participant behaviour is tested independently of the coordinator: a stubbed
// composable lets each test decide whether the coordinator grants presentation.
const managed = vi.hoisted(() => ({ allow: true, canPresent: { value: true } as { value: boolean }, opts: null as null | { id: string, priority: number, eligible: { value: boolean } } }))
vi.mock('../../../composables/useManagedDialog', async () => {
	const { computed, ref } = await import('vue')
	const gate = ref(managed.allow)
	managed.canPresent = gate
	return {
		useManagedDialog: (opts: { id: string, priority: number, eligible: { value: boolean } }) => {
			managed.opts = opts
			// Re-read per mount: the factory result is cached across resetModules().
			gate.value = managed.allow
			managed.canPresent = gate
			return { canPresent: computed(() => gate.value) }
		},
	}
})

vi.mock('@nextcloud/l10n', () => createL10nMock())

vi.mock('../../../services/publicWorkflow', () => ({
	getClaimStatus: (...args: unknown[]) => getClaimStatusMock(...args),
	claimWorkflow: (...args: unknown[]) => claimWorkflowMock(...args),
}))

function spyLocationHref(): ReturnType<typeof vi.fn> {
	const hrefSetter = vi.fn()
	Object.defineProperty(window.location, 'href', { configurable: true, set: hrefSetter })
	return hrefSetter
}

async function mountDialog() {
	const { default: PublicWorkflowClaimDialog } = await import('../../../components/PublicWorkflow/PublicWorkflowClaimDialog.vue')
	const wrapper = mount(PublicWorkflowClaimDialog, {
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /></div>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcButton: { template: '<button @click="$emit(\'click\')"><slot /><slot name="icon" /></button>' },
				NcLoadingIcon: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('PublicWorkflowClaimDialog.vue', () => {
	describe('coordinator gating', () => {
		it('registers as "pw-claim" with priority 30', async () => {
			getClaimStatusMock.mockResolvedValue({ claimable: false })
			await mountDialog()
			expect(managed.opts).toMatchObject({ id: 'pw-claim', priority: 30 })
		})

		it('is ineligible until the server says the draft is claimable', async () => {
			getClaimStatusMock.mockResolvedValue({ claimable: false })
			await mountDialog()
			expect(managed.opts!.eligible.value).toBe(false)
		})

		it('is eligible but stays closed while the coordinator withholds presentation', async () => {
			managed.allow = false
			getClaimStatusMock.mockResolvedValue({ claimable: true })
			const wrapper = await mountDialog()
			expect(managed.opts!.eligible.value).toBe(true)
			expect(wrapper.find('.pwc').exists()).toBe(false)
		})

		it('opens when the coordinator later grants presentation', async () => {
			managed.allow = false
			getClaimStatusMock.mockResolvedValue({ claimable: true })
			const wrapper = await mountDialog()
			managed.canPresent.value = true
			await flushPromises()
			expect(wrapper.find('.pwc').exists()).toBe(true)
		})

		it('Not now makes it ineligible so ownership is released and it does not reopen', async () => {
			getClaimStatusMock.mockResolvedValue({ claimable: true })
			const wrapper = await mountDialog()
			await wrapper.findAll('button')[0].trigger('click')
			expect(wrapper.find('.pwc').exists()).toBe(false)
			expect(managed.opts!.eligible.value).toBe(false)
			managed.canPresent.value = false
			managed.canPresent.value = true
			await flushPromises()
			expect(wrapper.find('.pwc').exists()).toBe(false)
		})

		it('a failed status check leaves it closed and ineligible', async () => {
			getClaimStatusMock.mockRejectedValue(new Error('401'))
			const wrapper = await mountDialog()
			expect(wrapper.find('.pwc').exists()).toBe(false)
			expect(managed.opts!.eligible.value).toBe(false)
		})
	})

	beforeEach(() => {
		managed.allow = true
		getClaimStatusMock.mockReset()
		claimWorkflowMock.mockReset()
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('stays closed when there is nothing claimable', async () => {
		getClaimStatusMock.mockResolvedValue({ claimable: false })
		const wrapper = await mountDialog()
		expect(wrapper.find('.pwc').exists()).toBe(false)
	})

	it('never renders the full or masked-looking requester email as plain text, only the masked hint', async () => {
		getClaimStatusMock.mockResolvedValue({
			claimable: true,
			requesterEmailMasked: 'br•••••@g•••••.co.ke',
			emailVerificationRequired: true,
			alreadyClaimedByMe: false,
		})
		const wrapper = await mountDialog()

		expect(wrapper.text()).toContain('br•••••@g•••••.co.ke')
		expect(wrapper.text()).not.toContain('bruno@gopaperless.co.ke')
		// The email input is present and required when verification is needed.
		expect(wrapper.find('#pwc-email').exists()).toBe(true)
	})

	it('disables the primary action until a plausible email is entered, then submits it', async () => {
		getClaimStatusMock.mockResolvedValue({
			claimable: true,
			requesterEmailMasked: 'br•••••@g•••••.co.ke',
			emailVerificationRequired: true,
			alreadyClaimedByMe: false,
		})
		claimWorkflowMock.mockResolvedValue({ uuid: 'u', redirectUrl: '/sign?uuid=u' })
		const hrefSetter = spyLocationHref()
		const wrapper = await mountDialog()

		const buttons = wrapper.findAll('button')
		const primary = buttons[buttons.length - 1]

		// No email yet: clicking must not call claimWorkflow at all.
		await primary.trigger('click')
		expect(claimWorkflowMock).not.toHaveBeenCalled()

		await wrapper.find('#pwc-email').setValue('bruno@gopaperless.co.ke')
		await primary.trigger('click')
		await flushPromises()

		expect(claimWorkflowMock).toHaveBeenCalledWith('bruno@gopaperless.co.ke')
		expect(hrefSetter).toHaveBeenCalledWith('/sign?uuid=u')
	})

	it('shows no email input and still claims when the workflow has no stored requester email', async () => {
		getClaimStatusMock.mockResolvedValue({
			claimable: true,
			requesterEmailMasked: null,
			emailVerificationRequired: false,
			alreadyClaimedByMe: false,
		})
		claimWorkflowMock.mockResolvedValue({ uuid: 'u', redirectUrl: '/sign?uuid=u' })
		spyLocationHref()
		const wrapper = await mountDialog()

		expect(wrapper.find('#pwc-email').exists()).toBe(false)

		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()

		expect(claimWorkflowMock).toHaveBeenCalledWith(null)
	})

	it('surfaces the server\'s own non-revealing message on a failed email match, without inventing one', async () => {
		getClaimStatusMock.mockResolvedValue({
			claimable: true,
			requesterEmailMasked: 'br•••••@g•••••.co.ke',
			emailVerificationRequired: true,
			alreadyClaimedByMe: false,
		})
		claimWorkflowMock.mockRejectedValue({
			response: { data: { ocs: { data: { message: "We couldn't verify that email." } } } },
		})
		const wrapper = await mountDialog()

		await wrapper.find('#pwc-email').setValue('wrong@example.com')
		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain("We couldn't verify that email.")
		expect(wrapper.text()).not.toContain('wrong@example.com')
	})
})
