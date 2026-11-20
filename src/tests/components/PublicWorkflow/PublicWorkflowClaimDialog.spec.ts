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
	beforeEach(() => {
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
