/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowSigners from '../../../../components/RightSidebar/Workflow/WorkflowSigners.vue'

vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/initial-state', () => ({ loadState: (_a: string, _k: string, f: unknown) => f }))

const doc = vi.hoisted(() => ({ value: null as any }))

vi.mock('../../../../store/files', () => ({
	useFilesStore: () => ({
		getFile: () => doc.value,
		isOriginalFileDeleted: () => false,
		canAddSigner: () => true,
		canSave: () => true,
		canRequestSign: true,
	}),
}))

vi.mock('../../../../composables/useWorkflowController', () => ({
	useWorkflowController: () => ({
		methods: ref([]),
		isSignerSigned: () => false,
		canSignerActInOrder: () => true,
	}),
}))

const signers = [
	{ localKey: 'signer:1', signRequestId: 1, signingOrder: 1 },
	{ localKey: 'signer:2', signRequestId: 2, signingOrder: 2 },
]

function mountSigners(props: Record<string, unknown> = {}) {
	doc.value = { id: 1, status: 1, signatureFlow: 'ordered_numeric', signers: [...signers] }
	return mount(WorkflowSigners, {
		props: { canManageSigners: true, signers: signers as never, ...props },
		global: {
			stubs: {
				NcButton: { name: 'NcButton', props: ['disabled'], template: '<button class="add" :disabled="disabled"><slot /></button>' },
				NcCheckboxRadioSwitch: true,
				NcIconSvgWrapper: true,
				WorkflowSigner: { name: 'WorkflowSigner', props: ['signer', 'pendingAction', 'deletePending', 'deleteBlocked'], template: '<div class="row" />' },
				draggable: {
					name: 'draggable',
					props: ['disabled', 'modelValue'],
					template: '<div class="drag"><slot name="item" v-for="(s, i) in modelValue" :element="s" :index="i" /></div>',
				},
			},
		},
	})
}

describe('WorkflowSigners.vue delete-pending locks (WS4)', () => {
	it('is fully interactive when no delete is pending', async () => {
		const w = mountSigners()
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(false)
		expect(w.find('button.add').attributes('disabled')).toBeUndefined()
		w.findComponent({ name: 'draggable' }).vm.$emit('end', { oldIndex: 0, newIndex: 1 })
		expect(w.emitted('signing-order-changed')).toHaveLength(1)
	})

	it('disables drag reordering, the Add button and rows while a delete is pending', async () => {
		const w = mountSigners({ pendingActions: { 'signer:1': 'delete' } })
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(true)
		expect(w.find('button.add').attributes('disabled')).toBeDefined()
		const rows = w.findAllComponents({ name: 'WorkflowSigner' })
		expect(rows).toHaveLength(2)
		rows.forEach(r => expect(r.props('deletePending')).toBe(true))
		expect(rows[0].props('pendingAction')).toBe('delete')
		expect(rows[1].props('pendingAction')).toBeNull()
	})

	it('ignores a drag-end that still arrives during a pending delete (backstop)', async () => {
		const w = mountSigners({ pendingActions: { 'signer:2': 'delete' } })
		await flushPromises()
		w.findComponent({ name: 'draggable' }).vm.$emit('end', { oldIndex: 0, newIndex: 1 })
		expect(w.emitted('signing-order-changed')).toBeUndefined()
		expect(doc.value.signers.map((s: any) => s.signingOrder)).toEqual([1, 2])
	})

	it('a notify or WhatsApp pending does not lock reordering or entry points', async () => {
		const w = mountSigners({ pendingActions: { 'signer:1': 'notify', 'signer:2': 'whatsapp' } })
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(false)
		expect(w.find('button.add').attributes('disabled')).toBeUndefined()
	})

	it('disables drag reordering during a request-level mutation, and only drag', async () => {
		const w = mountSigners({ requestLoading: true })
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(true)
		expect(w.find('button.add').attributes('disabled')).toBeUndefined()
	})

	it('ignores a drag-end that still arrives during a request-level mutation (backstop)', async () => {
		const w = mountSigners({ requestLoading: true })
		await flushPromises()
		w.findComponent({ name: 'draggable' }).vm.$emit('end', { oldIndex: 0, newIndex: 1 })
		expect(w.emitted('signing-order-changed')).toBeUndefined()
		expect(doc.value.signers.map((s: any) => s.signingOrder)).toEqual([1, 2])
	})

	it('keeps drag enabled when only requestBusy is set (autosave executing / delete-blocked)', async () => {
		const w = mountSigners({ requestBusy: true, requestLoading: false })
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(false)
	})

	it('keeps drag disabled during a delete even when no request-level mutation is active', async () => {
		const w = mountSigners({ pendingActions: { 'signer:1': 'delete' }, requestLoading: false })
		await flushPromises()
		expect(w.findComponent({ name: 'draggable' }).props('disabled')).toBe(true)
	})

	it('forwards requestBusy as deleteBlocked to rows', async () => {
		const w = mountSigners({ requestBusy: true })
		await flushPromises()
		w.findAllComponents({ name: 'WorkflowSigner' }).forEach(r => expect(r.props('deleteBlocked')).toBe(true))
	})
})
