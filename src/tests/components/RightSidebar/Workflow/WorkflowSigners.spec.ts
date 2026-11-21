/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref, TransitionGroup } from 'vue'

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

describe('WorkflowSigners.vue row motion (Phase 3)', () => {
	let frames: Array<() => void>
	const flushFrames = () => {
		for (let i = 0; i < 2; i++) {
			const queued = frames
			frames = []
			queued.forEach((cb) => cb())
		}
	}

	beforeEach(() => {
		frames = []
		vi.stubGlobal('requestAnimationFrame', (cb: () => void) => {
			frames.push(cb)
			return frames.length
		})
		vi.stubGlobal('cancelAnimationFrame', () => {
			frames = []
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	function mountStatic(props: Record<string, unknown> = {}) {
		const w = mountSigners(props)
		return w
	}

	function staticList(motionProps: Record<string, unknown> = {}) {
		doc.value = { id: 1, status: 1, signatureFlow: 'parallel', signers: [...signers] }
		return mount(WorkflowSigners, {
			props: { canManageSigners: true, signers: signers as never, ...motionProps },
			global: {
				stubs: {
					NcButton: true,
					NcCheckboxRadioSwitch: true,
					NcIconSvgWrapper: true,
					WorkflowSigner: { name: 'WorkflowSigner', template: '<div class="row" />' },
					draggable: { name: 'draggable', template: '<div class="drag" />' },
				},
			},
		})
	}

	function fakeRow(top: number, left: number, width: number, height: number): HTMLElement {
		const el = document.createElement('div')
		Object.defineProperty(el, 'offsetTop', { value: top })
		Object.defineProperty(el, 'offsetLeft', { value: left })
		Object.defineProperty(el, 'offsetWidth', { value: width })
		Object.defineProperty(el, 'offsetHeight', { value: height })
		return el
	}

	const group = (w: ReturnType<typeof staticList>) => w.findComponent(TransitionGroup)
	const emitOn = (w: ReturnType<typeof staticList>, name: string, el: HTMLElement) => {
		;(group(w).vm as unknown as { $emit: (e: string, el: HTMLElement) => void }).$emit(name, el)
	}

	it('keeps the animated class and positioning off the draggable (ordered) list', async () => {
		const w = mountStatic()
		await flushPromises()

		expect(w.find('.workflow-signer-list--animated').exists()).toBe(false)
	})

	it('marks only the static list as the animated, positioned list', async () => {
		const w = staticList({ motionKey: 1 })
		await flushPromises()

		expect(w.find('.workflow-signer-list').classes()).toContain('workflow-signer-list--animated')
	})

	it('pins the leaving row at its measured box and touches nothing else', async () => {
		const w = staticList({ motionKey: 1 })
		const row = fakeRow(120, 0, 312, 74)
		const sibling = fakeRow(0, 0, 312, 74)

		group(w).vm.$emit('before-leave', row)

		expect(row.style.top).toBe('120px')
		expect(row.style.left).toBe('0px')
		expect(row.style.width).toBe('312px')
		expect(row.style.height).toBe('74px')
		expect(sibling.getAttribute('style')).toBeNull()
		// position/opacity come from the leave-active class, not inline
		expect(row.style.position).toBe('')
	})

	it('clears the measured box when a leave is cancelled', async () => {
		const w = staticList({ motionKey: 1 })
		const row = fakeRow(120, 0, 312, 74)

		group(w).vm.$emit('before-leave', row)
		group(w).vm.$emit('leave-cancelled', row)

		expect(row.style.top).toBe('')
		expect(row.style.width).toBe('')
		expect(row.style.height).toBe('')
	})

	it('does not pin and disables css transitions in the render after a document switch', async () => {
		const w = staticList({ motionKey: 1 })
		await w.setProps({ motionKey: 2 })

		expect(group(w).attributes('css')).toBe('false')
		const row = fakeRow(10, 0, 300, 70)
		group(w).vm.$emit('before-leave', row)
		expect(row.getAttribute('style')).toBeNull()

		flushFrames()
		await w.vm.$nextTick()
		expect(group(w).attributes('css')).toBe('true')
	})

	it('animates normally without a motion key (previous behaviour preserved)', async () => {
		const w = staticList()

		expect(group(w).attributes('css')).toBe('true')
		const row = fakeRow(40, 0, 300, 70)
		group(w).vm.$emit('before-leave', row)
		expect(row.style.top).toBe('40px')
	})
	it('ends with exactly the remaining rows after rapid first/middle/last removals (real TransitionGroup)', async () => {
		const five = [1, 2, 3, 4, 5].map((n) => ({ localKey: `signer:${n}`, signRequestId: n, signingOrder: n }))
		doc.value = { id: 1, status: 1, signatureFlow: 'parallel', signers: [...five] }
		const w = mount(WorkflowSigners, {
			props: { canManageSigners: true, signers: five as never, motionKey: 1 },
			global: {
				stubs: {
					'transition-group': false,
					NcButton: true,
					NcCheckboxRadioSwitch: true,
					NcIconSvgWrapper: true,
					WorkflowSigner: {
						name: 'WorkflowSigner',
						props: ['signer'],
						template: '<div class="row" :data-key="signer.localKey" />',
					},
					draggable: { name: 'draggable', template: '<div class="drag" />' },
				},
			},
		})
		const keys = () => w.findAll('.row').map((r) => r.attributes('data-key'))
		const settle = () => new Promise((resolve) => setTimeout(resolve, 60))

		expect(w.findComponent(TransitionGroup).exists()).toBe(true)
		expect(w.find('transition-group-stub').exists()).toBe(false)

		// middle, then the row now in the middle, then last, then first: each before the previous settles
		await w.setProps({ signers: five.filter((s) => s.signRequestId !== 3) as never })
		await w.setProps({ signers: five.filter((s) => ![3, 2].includes(s.signRequestId)) as never })
		await w.setProps({ signers: five.filter((s) => ![3, 2, 5].includes(s.signRequestId)) as never })
		await w.setProps({ signers: five.filter((s) => ![3, 2, 5, 1].includes(s.signRequestId)) as never })
		for (let i = 0; i < 4; i++) {
			flushFrames()
			await settle()
		}

		expect(keys()).toEqual(['signer:4'])
		w.findAll('.row').forEach((r) => expect(r.attributes('style') ?? '').not.toContain('position'))
	})
})
