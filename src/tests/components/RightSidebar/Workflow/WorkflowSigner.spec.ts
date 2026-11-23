/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowSigner from '../../../../components/RightSidebar/Workflow/WorkflowSigner.vue'

vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/initial-state', () => ({ loadState: (_a: string, _k: string, f: unknown) => f }))
const fetchRecipient = vi.hoisted(() => vi.fn())
vi.mock('../../../../services/whatsapp', () => ({
	fetchSignLink: vi.fn(),
	fetchWhatsAppRecipient: (...args: unknown[]) => fetchRecipient(...args),
}))

const NcActionButtonStub = {
	name: 'NcActionButton',
	props: ['disabled'],
	template: '<button class="action" :disabled="disabled"><slot /></button>',
}

function mountRow(props: Record<string, unknown> = {}) {
	return mount(WorkflowSigner, {
		props: {
			signer: { localKey: 'signer:1', signRequestId: 1, displayName: 'A' } as never,
			index: 0,
			canEdit: true,
			canDelete: true,
			canSendReminder: true,
			...props,
		},
		global: {
			stubs: {
				NcActions: { name: 'NcActions', template: '<div class="actions"><slot /></div>' },
				NcActionButton: NcActionButtonStub,
				NcActionCaption: true,
				NcIconSvgWrapper: true,
				NcDialog: true,
				NcButton: true,
				NcLoadingIcon: { name: 'NcLoadingIcon', props: ['name'], template: '<span class="spinner" :data-name="name" />' },
			},
		},
	})
}

function button(wrapper: ReturnType<typeof mountRow>, label: string) {
	return wrapper.findAll('button.action').find(b => b.text().includes(label))!
}

describe('WorkflowSigner.vue pending action feedback (WS4)', () => {
	it('shows no spinner and enables reminder/delete when idle', async () => {
		const w = mountRow()
		await flushPromises()
		expect(w.find('.spinner').exists()).toBe(false)
		expect(button(w, 'Send Reminder').attributes('disabled')).toBeUndefined()
		expect(button(w, 'Delete Signer').attributes('disabled')).toBeUndefined()
	})

	it.each([
		['notify', 'Sending reminder'],
		['whatsapp', 'Sending on WhatsApp'],
		['delete', 'Deleting'],
	])('shows a labelled spinner and disables reminder and delete for %s', async (pendingAction, label) => {
		const w = mountRow({ pendingAction })
		await flushPromises()
		expect(w.find('.spinner').attributes('data-name')).toContain(label)
		expect(button(w, 'Send Reminder').attributes('disabled')).toBeDefined()
		expect(button(w, 'Delete Signer').attributes('disabled')).toBeDefined()
		expect(w.find('.workflow-signer-actions').classes()).toContain('workflow-signer-actions--pending')
	})

	it('deleteBlocked disables only Delete', async () => {
		const w = mountRow({ deleteBlocked: true })
		await flushPromises()
		expect(w.find('.spinner').exists()).toBe(false)
		expect(button(w, 'Delete Signer').attributes('disabled')).toBeDefined()
		expect(button(w, 'Send Reminder').attributes('disabled')).toBeUndefined()
		expect(button(w, 'Edit Signer').attributes('disabled')).toBeUndefined()
	})

	it('deletePending locks Edit, Customise and WhatsApp but leaves Reminder and Delete to their own state', async () => {
		fetchRecipient.mockResolvedValue({ available: true, masked: '+254 7•• ••• 678', reason: null, lastSentAt: null, availableAgainAt: null })
		const idle = mountRow({ canShare: true, fileId: 10, canCustomizeMessage: true })
		await flushPromises()
		expect(button(idle, 'Edit Signer').attributes('disabled')).toBeUndefined()
		expect(button(idle, 'Customise message').attributes('disabled')).toBeUndefined()
		expect(button(idle, 'on WhatsApp').attributes('disabled')).toBeUndefined()

		const w = mountRow({ canShare: true, fileId: 10, canCustomizeMessage: true, deletePending: true })
		await flushPromises()
		expect(button(w, 'Edit Signer').attributes('disabled')).toBeDefined()
		expect(button(w, 'Customise message').attributes('disabled')).toBeDefined()
		expect(button(w, 'on WhatsApp').attributes('disabled')).toBeDefined()
		expect(button(w, 'Send Reminder').attributes('disabled')).toBeUndefined()
	})

	it('does not emit from a disabled-state row beyond normal button clicks', async () => {
		const w = mountRow()
		await flushPromises()
		await button(w, 'Delete Signer').trigger('click')
		expect(w.emitted('delete')).toHaveLength(1)
	})
})
