/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

import { createL10nMock } from '../testHelpers/l10n.js'

const h_ = vi.hoisted(() => ({
	axiosPost: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	emitEventBus: vi.fn(),
	stateCache: {} as Record<string, unknown>,
	files: {
		getEditableFile: vi.fn(),
		saveOrUpdateSignatureRequest: vi.fn(),
		deleteSigner: vi.fn(),
		getFile: vi.fn(),
		fetchFileDetail: vi.fn(),
		disableIdentifySigner: vi.fn(),
		enableIdentifySigner: vi.fn(),
		identifyingSigner: false,
		selectedFileId: 10,
	} as Record<string, any>,
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a: unknown[]) => h_.axiosPost(...a), get: vi.fn() } }))
vi.mock('@nextcloud/capabilities', () => ({ getCapabilities: () => ({}) }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (_a: string, _k: string, f: unknown) => f }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (u: string) => u, generateUrl: (u: string) => u }))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (...a: unknown[]) => h_.emitEventBus(...a),
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))
vi.mock('../../services/toast', () => ({
	showError: (...a: unknown[]) => h_.showError(...a),
	showSuccess: (...a: unknown[]) => h_.showSuccess(...a),
	showWarning: (...a: unknown[]) => h_.showWarning(...a),
}))
vi.mock('../../services/longPolling', () => ({ startLongPolling: vi.fn(() => vi.fn()) }))
vi.mock('../../utils/viewer', () => ({ openDocument: vi.fn() }))
vi.mock('../../router/router', () => ({ default: { push: vi.fn(), resolve: vi.fn(() => ({ href: '' })) } }))
vi.mock('../../composables/useSigningOrder', () => ({ useSigningOrder: () => ({ normalizeSigningOrders: (s: unknown) => s }) }))
vi.mock('../../store/files', () => ({ useFilesStore: () => h_.files }))
vi.mock('../../store/sidebar', () => ({ useSidebarStore: () => ({}) }))
vi.mock('../../store/sign', () => ({ useSignStore: () => ({}) }))
vi.mock('../../store/userconfig', () => ({ useUserConfigStore: () => ({}) }))
vi.mock('../../composables/useWorkflowState', () => {
	const cache = h_.stateCache
	return {
		isSignerSigned: () => false,
		useWorkflowState: () => new Proxy({}, {
			get: (_t, key: string) => (cache[key] ??= ref(key === 'file' ? { id: 1, status: 1 } : null)),
		}),
	}
})

import { useWorkflowController, type WorkflowController } from '../../composables/useWorkflowController'

function deferred<T = void>() {
	let resolve!: (v: T) => void
	let reject!: (e: unknown) => void
	const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej })
	return { promise, resolve, reject }
}

// Reactive selection so the controller's selection watcher sees file switches.
const selectedFileIdRef = ref<number>(10)
Object.defineProperty(h_.files, 'selectedFileId', {
	configurable: true,
	get: () => selectedFileIdRef.value,
	set: (v: number) => { selectedFileIdRef.value = v },
})

let lastWrapper: ReturnType<typeof mount>
const mountedWrappers: Array<ReturnType<typeof mount>> = []

function setup(): WorkflowController {
	let controller!: WorkflowController
	lastWrapper = mount(defineComponent({
		setup() {
			controller = useWorkflowController()
			return () => h('div')
		},
	}))
	mountedWrappers.push(lastWrapper)
	return controller
}

const signerA = { localKey: 'signer:1', signRequestId: 1 } as never
const signerB = { localKey: 'signer:2', signRequestId: 2 } as never

describe('useWorkflowController per-signer loading (WS4)', () => {
	let c: WorkflowController

	// Controllers share the reactive selection mock; unmount them so a previous
	// test's retained intents cannot react to the next test's file switches.
	afterEach(() => {
		mountedWrappers.splice(0).forEach((w) => { try { w.unmount() } catch { /* already unmounted */ } })
	})

	beforeEach(() => {
		vi.clearAllMocks()
		h_.files.identifyingSigner = false
		h_.files.selectedFileId = 10
		for (const k of Object.keys(h_.stateCache)) delete h_.stateCache[k]
		h_.files.getEditableFile.mockReturnValue({ id: 10, status: 1, signers: [] })
		h_.files.saveOrUpdateSignatureRequest.mockResolvedValue({ success: true })
		h_.files.deleteSigner.mockResolvedValue(undefined)
		h_.files.fetchFileDetail.mockResolvedValue(undefined)
		h_.axiosPost.mockResolvedValue({ data: { ocs: { data: { message: 'ok' } } } })
		c = setup()
	})

	describe('notify', () => {
		it('issues one request for repeated clicks on the same signer and clears pending', async () => {
			const d = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValue(d.promise)

			const first = c.sendNotify(signerA)
			const second = c.sendNotify(signerA)
			expect(c.pendingSignerActions.value['signer:1']).toBe('notify')
			expect(h_.axiosPost).toHaveBeenCalledTimes(1)

			d.resolve({ data: { ocs: { data: { message: 'Reminder sent' } } } })
			await Promise.all([first, second])

			expect(c.pendingSignerActions.value).toEqual({})
			expect(h_.showSuccess).toHaveBeenCalledTimes(1)
			expect(c.hasLoading.value).toBe(false)
		})

		it('keeps notify on different signers independent and never touches hasLoading', async () => {
			const a = deferred<{ data: unknown }>()
			const b = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValueOnce(a.promise).mockReturnValueOnce(b.promise)

			const pa = c.sendNotify(signerA)
			const pb = c.sendNotify(signerB)
			expect(h_.axiosPost).toHaveBeenCalledTimes(2)
			expect(c.pendingSignerActions.value).toEqual({ 'signer:1': 'notify', 'signer:2': 'notify' })
			expect(c.hasLoading.value).toBe(false)

			a.resolve({ data: {} })
			await pa
			expect(c.pendingSignerActions.value).toEqual({ 'signer:2': 'notify' })
			b.resolve({ data: {} })
			await pb
			expect(c.pendingSignerActions.value).toEqual({})
		})

		it('clears pending and shows the existing error toast on failure', async () => {
			h_.axiosPost.mockRejectedValue(new Error('boom'))
			await c.sendNotify(signerA)
			expect(c.pendingSignerActions.value).toEqual({})
			expect(h_.showError).toHaveBeenCalledTimes(1)
		})

		it('is allowed while a delete is pending on another signer', async () => {
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerB)
			await c.sendNotify(signerA)
			expect(h_.axiosPost).toHaveBeenCalledTimes(1)
			del.resolve()
			await pd
		})
	})

	describe('whatsapp (feedback only)', () => {
		it('tracks pending per signer, dedups the same signer, keeps others independent', async () => {
			const post = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValue(post.promise)

			const first = c.sendWhatsApp(signerA)
			const dup = c.sendWhatsApp(signerA)
			await flushPromises()
			expect(c.pendingSignerActions.value['signer:1']).toBe('whatsapp')
			expect(h_.axiosPost).toHaveBeenCalledTimes(1)
			expect(h_.axiosPost.mock.calls[0][1]).toBeUndefined()

			post.resolve({ data: { ocs: { data: { status: 'sent' } } } })
			await Promise.all([first, dup])
			expect(c.pendingSignerActions.value).toEqual({})
			expect(h_.showSuccess).toHaveBeenCalledTimes(1)
		})

		it('hasLoading is still owned by the WhatsApp flow and never becomes a pending entry', async () => {
			const post = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValue(post.promise)
			const p = c.sendWhatsApp(signerA)
			await flushPromises()
			expect(c.hasLoading.value).toBe(true)
			expect(c.isDeletePending.value).toBe(false)
			post.resolve({ data: { ocs: { data: { status: 'sent' } } } })
			await p
			expect(c.hasLoading.value).toBe(false)
		})

		it('completing WhatsApp cannot clear a delete or other pending entries', async () => {
			// WhatsApp (A) in flight with a notify (B) pending: finishing A leaves B alone.
			const wa = deferred<{ data: unknown }>()
			const nb = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValueOnce(wa.promise).mockReturnValueOnce(nb.promise)
			const pw = c.sendWhatsApp(signerA)
			await flushPromises()
			const pn = c.sendNotify(signerB)
			wa.resolve({ data: { ocs: { data: { status: 'sent' } } } })
			await pw
			expect(c.pendingSignerActions.value).toEqual({ 'signer:2': 'notify' })
			nb.resolve({ data: {} })
			await pn
		})

		it('a delete started afterwards is refused while WhatsApp holds hasLoading, and survives its completion', async () => {
			const wa = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValue(wa.promise)
			const pw = c.sendWhatsApp(signerA)
			await flushPromises()
			await c.deleteSigner(signerB)
			expect(h_.files.deleteSigner).not.toHaveBeenCalled()
			wa.resolve({ data: { ocs: { data: { status: 'sent' } } } })
			await pw
			await c.deleteSigner(signerB)
			expect(h_.files.deleteSigner).toHaveBeenCalledTimes(1)
		})

		it('a WhatsApp send that finishes mid-delete leaves the delete pending', async () => {
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerB)
			const wa = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValue(wa.promise)
			const pw = c.sendWhatsApp(signerA)
			await flushPromises()
			wa.resolve({ data: { ocs: { data: { status: 'sent' } } } })
			await pw
			expect(c.pendingSignerActions.value).toEqual({ 'signer:2': 'delete' })
			expect(c.isDeletePending.value).toBe(true)
			expect(c.isRequestBusy.value).toBe(true)
			del.resolve()
			await pd
			expect(c.isDeletePending.value).toBe(false)
		})
	})

	describe('delete serialization', () => {
		it('refuses a second delete while one is pending', async () => {
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const first = c.deleteSigner(signerA)
			expect(c.isDeletePending.value).toBe(true)
			await c.deleteSigner(signerB)
			expect(h_.files.deleteSigner).toHaveBeenCalledTimes(1)
			del.resolve()
			await first
			expect(c.pendingSignerActions.value).toEqual({})
		})

		it('refuses delete while a request-level mutation holds hasLoading', async () => {
			const save = deferred<{ success: boolean }>()
			h_.files.saveOrUpdateSignatureRequest.mockReturnValue(save.promise)
			const ps = c.save()
			await flushPromises()
			expect(c.hasLoading.value).toBe(true)
			await c.deleteSigner(signerA)
			expect(h_.files.deleteSigner).not.toHaveBeenCalled()
			save.resolve({ success: true })
			await ps
			expect(c.hasLoading.value).toBe(false)
		})

		it.each([
			['save', (x: WorkflowController) => x.save()],
			['confirmRequest', (x: WorkflowController) => x.confirmRequest()],
			['openManageFiles', (x: WorkflowController) => x.openManageFiles()],
		])('refuses %s while a delete is pending', async (_n, run) => {
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerA)
			await run(c)
			expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()
			del.resolve()
			await pd
		})

		it('refuses confirmRequestSigner while a delete is pending', async () => {
			c.selectedSigner.value = signerB
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerA)
			await c.confirmRequestSigner()
			expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()
			del.resolve()
			await pd
		})

		it('shows an error toast and clears pending when delete fails', async () => {
			h_.files.deleteSigner.mockRejectedValue(new Error('nope'))
			await c.deleteSigner(signerA)
			expect(h_.showError).toHaveBeenCalledTimes(1)
			expect(c.pendingSignerActions.value).toEqual({})
			expect(c.isDeletePending.value).toBe(false)
		})

		it('does not toast on a successful delete', async () => {
			await c.deleteSigner(signerA)
			expect(h_.showError).not.toHaveBeenCalled()
			expect(h_.showSuccess).not.toHaveBeenCalled()
		})
	})

	describe('request-level hasLoading lifecycle', () => {
		it('save sets hasLoading before the detail fetch and ignores a re-entrant call', async () => {
			const save = deferred<{ success: boolean }>()
			h_.files.saveOrUpdateSignatureRequest.mockReturnValue(save.promise)
			const p1 = c.save()
			expect(c.hasLoading.value).toBe(true)
			const p2 = c.save()
			await flushPromises()
			save.resolve({ success: true })
			await Promise.all([p1, p2])
			expect(h_.files.saveOrUpdateSignatureRequest).toHaveBeenCalledTimes(1)
			expect(c.hasLoading.value).toBe(false)
		})

		it('openManageFiles clears hasLoading when the store throws and still propagates', async () => {
			h_.files.saveOrUpdateSignatureRequest.mockRejectedValue(new Error('x'))
			await expect(c.openManageFiles()).rejects.toThrow('x')
			expect(c.hasLoading.value).toBe(false)
		})

		it('openManageFiles keeps its success/failure semantics', async () => {
			await c.openManageFiles()
			expect(c.showEnvelopeFilesDialog.value).toBe(true)

			c.showEnvelopeFilesDialog.value = false
			h_.files.saveOrUpdateSignatureRequest.mockResolvedValue({ success: false, message: 'bad' })
			await c.openManageFiles()
			expect(h_.showError).toHaveBeenCalledWith('bad')
			expect(c.showEnvelopeFilesDialog.value).toBe(false)
		})

		it('confirmRequestSigner result semantics are unchanged ({success:false} is still treated as success)', async () => {
			c.selectedSigner.value = signerA
			h_.files.saveOrUpdateSignatureRequest.mockResolvedValue({ success: false, message: 'ignored' })
			await c.confirmRequestSigner()
			expect(h_.showSuccess).toHaveBeenCalledTimes(1)
			expect(c.hasLoading.value).toBe(false)
		})
	})

	describe('whatsapp vs delete symmetry (WS4 corrections)', () => {
		it('WhatsApp cannot start while a delete is pending', async () => {
			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerB)

			await c.sendWhatsApp(signerA)
			await flushPromises()

			expect(h_.axiosPost).not.toHaveBeenCalled()
			expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()
			expect(c.pendingSignerActions.value).toEqual({ 'signer:2': 'delete' })
			expect(c.hasLoading.value).toBe(false)

			del.resolve()
			await pd
		})

		it('WhatsApp starts again after the delete settles', async () => {
			await c.deleteSigner(signerB)
			await c.sendWhatsApp(signerA)
			expect(h_.axiosPost).toHaveBeenCalledTimes(1)
		})

		it('WhatsApp on two different signers still runs independently', async () => {
			const a = deferred<{ data: unknown }>()
			const b = deferred<{ data: unknown }>()
			h_.axiosPost.mockReturnValueOnce(a.promise).mockReturnValueOnce(b.promise)
			const pa = c.sendWhatsApp(signerA)
			const pb = c.sendWhatsApp(signerB)
			await flushPromises()
			expect(h_.axiosPost).toHaveBeenCalledTimes(2)
			a.resolve({ data: {} })
			b.resolve({ data: {} })
			await Promise.all([pa, pb])
			expect(c.pendingSignerActions.value).toEqual({})
		})
	})

	describe('signer dialog entry points (WS4 corrections)', () => {
		const signer = { localKey: 'signer:1', signRequestId: 1, displayName: 'A' } as never

		it.each([
			['editSigner', (x: WorkflowController) => x.editSigner(signer)],
			['customizeMessage', (x: WorkflowController) => x.customizeMessage(signer)],
		])('%s opens the dialog when idle and is a backstop no-op during a delete', async (_n, run) => {
			run(c)
			expect(h_.files.enableIdentifySigner).toHaveBeenCalledTimes(1)
			h_.files.enableIdentifySigner.mockClear()

			const del = deferred()
			h_.files.deleteSigner.mockReturnValue(del.promise)
			const pd = c.deleteSigner(signerA)
			run(c)
			c.addSigner()
			expect(h_.files.enableIdentifySigner).not.toHaveBeenCalled()
			del.resolve()
			await pd
		})

		it('delete is refused while the signer dialog is open', async () => {
			h_.files.identifyingSigner = true
			await c.deleteSigner(signerA)
			expect(h_.files.deleteSigner).not.toHaveBeenCalled()
			expect(c.isDeleteBlocked.value).toBe(true)
			h_.files.identifyingSigner = false
		})
	})

	describe('deferred autosave vs delete (WS4 corrections)', () => {
		let doc: { signers: Array<{ localKey: string }>, signatureFlow: string }

		const autosaveCalls = () => h_.files.saveOrUpdateSignatureRequest.mock.calls
			.map(([payload]: any[]) => payload)
			.filter((payload: any) => payload && 'signatureFlow' in payload)

		beforeEach(() => {
			vi.useFakeTimers()
			doc = { signers: [{ localKey: 'signer:1' }, { localKey: 'signer:2' }], signatureFlow: 'ordered_numeric' }
			h_.files.getFile.mockImplementation(() => doc)
			h_.stateCache.isOrderedNumeric = ref(true)
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		const settle = () => vi.advanceTimersByTimeAsync(0)
		const fireDebounce = () => vi.advanceTimersByTimeAsync(1000)

		function pendingDelete(applyOnResolve: boolean) {
			const d = deferred()
			h_.files.deleteSigner.mockImplementation(async (signer: { localKey: string }) => {
				await d.promise
				if (applyOnResolve) doc.signers = doc.signers.filter(s => s.localKey !== signer.localKey)
			})
			return d
		}

		it('autosave firing during a delete submits nothing, then runs once against the post-delete list', async () => {
			const d = pendingDelete(true)
			const pd = c.deleteSigner(signerA)
			c.debouncedSave()

			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(0)

			d.resolve()
			await pd
			await settle()
			await fireDebounce()

			expect(autosaveCalls()).toHaveLength(1)
			expect(autosaveCalls()[0].signers).toEqual([{ localKey: 'signer:2' }])
			expect(c.isAutoSaving.value).toBe(false)
		})

		it('a failed delete does not permanently suppress the deferred autosave', async () => {
			const d = deferred()
			h_.files.deleteSigner.mockImplementation(async () => {
				await d.promise
				throw new Error('nope')
			})
			const pd = c.deleteSigner(signerA)
			c.debouncedSave()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(0)

			d.resolve()
			await pd
			await settle()
			await fireDebounce()

			expect(h_.showError).toHaveBeenCalledTimes(1)
			expect(autosaveCalls()).toHaveLength(1)
			expect(autosaveCalls()[0].signers).toHaveLength(2)
		})

		it('defers again when another delete is active at the re-arm, and still runs once at the end', async () => {
			const first = pendingDelete(true)
			const p1 = c.deleteSigner(signerA)
			c.debouncedSave()
			await fireDebounce()

			first.resolve()
			await p1
			await settle()

			// The re-armed autosave is waiting for its timer; a second delete starts first.
			const second = pendingDelete(true)
			const p2 = c.deleteSigner(signerB)
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(0)

			second.resolve()
			await p2
			await settle()
			await fireDebounce()

			expect(autosaveCalls()).toHaveLength(1)
			expect(autosaveCalls()[0].signers).toEqual([])
		})

		it('defers while a request-level mutation holds hasLoading, then runs once', async () => {
			const save = deferred<{ success: boolean }>()
			h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(save.promise)
			const ps = c.save()
			await settle()
			expect(c.hasLoading.value).toBe(true)

			c.debouncedSave()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(0)

			save.resolve({ success: true })
			await ps
			await settle()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(1)
		})

		it('repeated triggers collapse to one timer and one request', async () => {
			c.debouncedSave()
			c.debouncedSave()
			c.debouncedSave()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(1)
		})

		it('does not run two autosave requests concurrently', async () => {
			const first = deferred<{ success: boolean }>()
			h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(first.promise)
			c.debouncedSave()
			await fireDebounce()
			expect(c.isAutoSaving.value).toBe(true)

			c.debouncedSave()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(1)

			first.resolve({ success: true })
			await settle()
			await fireDebounce()
			expect(autosaveCalls()).toHaveLength(2)
			expect(c.isAutoSaving.value).toBe(false)
		})

		it('delete is refused while an autosave is executing, and allowed once it finishes', async () => {
			const inflight = deferred<{ success: boolean }>()
			h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(inflight.promise)
			c.debouncedSave()
			await fireDebounce()
			expect(c.isAutoSaving.value).toBe(true)
			expect(c.isDeleteBlocked.value).toBe(true)

			await c.deleteSigner(signerA)
			expect(h_.files.deleteSigner).not.toHaveBeenCalled()

			inflight.resolve({ success: true })
			await settle()
			await c.deleteSigner(signerA)
			expect(h_.files.deleteSigner).toHaveBeenCalledTimes(1)
		})

		it('an autosave failure clears its busy state and shows the existing error toast', async () => {
			h_.files.saveOrUpdateSignatureRequest.mockRejectedValueOnce(new Error('boom'))
			c.debouncedSave()
			await fireDebounce()
			expect(c.isAutoSaving.value).toBe(false)
			expect(c.isDeleteBlocked.value).toBe(false)
			expect(h_.showError).toHaveBeenCalledTimes(1)
		})

		it('repeated delete clicks during autosave interplay create no duplicate operations', async () => {
			const d = pendingDelete(true)
			const first = c.deleteSigner(signerA)
			c.debouncedSave()
			await c.deleteSigner(signerA)
			await c.deleteSigner(signerB)
			await fireDebounce()
			c.debouncedSave()
			await fireDebounce()

			d.resolve()
			await first
			await settle()
			await fireDebounce()

			expect(h_.files.deleteSigner).toHaveBeenCalledTimes(1)
			expect(autosaveCalls()).toHaveLength(1)
		})
	})
	describe('autosave vs successful submission and teardown (WS4 corrections)', () => {
		let doc: { signers: Array<{ localKey: string }>, signatureFlow: string }

		const autosaveCalls = () => h_.files.saveOrUpdateSignatureRequest.mock.calls
			.map(([payload]: any[]) => payload)
			.filter((payload: any) => payload && 'signatureFlow' in payload)
		const submitCalls = () => h_.files.saveOrUpdateSignatureRequest.mock.calls
			.map(([payload]: any[]) => payload)
			.filter((payload: any) => payload && payload.status === 1)

		const settle = () => vi.advanceTimersByTimeAsync(0)
		const fireDebounce = () => vi.advanceTimersByTimeAsync(1000)

		beforeEach(() => {
			vi.useFakeTimers()
			doc = { signers: [{ localKey: 'signer:1' }, { localKey: 'signer:2' }], signatureFlow: 'ordered_numeric' }
			h_.files.getFile.mockImplementation(() => doc)
			h_.stateCache.isOrderedNumeric = ref(true)
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		// A submit that stays in flight until resolved; autosaves resolve immediately.
		function pendingSubmit() {
			const d = deferred<unknown>()
			h_.files.saveOrUpdateSignatureRequest.mockImplementation((payload: any) =>
				payload?.status === 1 ? d.promise : Promise.resolve({ success: true }))
			return d
		}

		describe('successful submission', () => {
			it('cancels an autosave that was deferred while the submission was in flight', async () => {
				const d = pendingSubmit()
				c.debouncedSave()
				const submit = c.confirmRequest()
				await settle()
				await fireDebounce() // fires while hasLoading: deferred, not sent
				expect(autosaveCalls()).toHaveLength(0)

				d.resolve({ success: true, message: 'ok' })
				await submit
				await settle()
				await fireDebounce()

				expect(autosaveCalls()).toHaveLength(0)
				expect(submitCalls()).toHaveLength(1)
			})

			it('cancels a still-pending timer once the submission has succeeded', async () => {
				c.debouncedSave()
				await c.confirmRequest() // resolves well before the 1000ms debounce
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
			})

			it('leaves the submitted payload and semantics unchanged', async () => {
				c.debouncedSave()
				await c.confirmRequest()
				expect(submitCalls()).toEqual([{ status: 1 }])
				expect(h_.showSuccess).toHaveBeenCalledTimes(1)
				expect(c.hasLoading.value).toBe(false)
			})

			it('preserves an intent recorded after the payload was serialized (deferred case)', async () => {
				const d = pendingSubmit()
				c.debouncedSave()
				const submit = c.confirmRequest()
				await settle()
				c.debouncedSave() // newer reorder, not part of the in-flight payload
				await fireDebounce() // deferred
				d.resolve({ success: true })
				await submit
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('preserves an intent recorded after the payload was serialized (timer still pending)', async () => {
				const d = pendingSubmit()
				c.debouncedSave()
				const submit = c.confirmRequest()
				await settle()
				c.debouncedSave()
				d.resolve({ success: true })
				await submit
				await settle()
				expect(autosaveCalls()).toHaveLength(0)
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})
		})

		describe('failed submission keeps the autosave', () => {
			const outcomes: Array<[string, (d: ReturnType<typeof deferred<unknown>>) => void]> = [
				['{success:false}', d => d.resolve({ success: false, message: 'bad' })],
				['{success:false, handled:true}', d => d.resolve({ success: false, handled: true })],
				['a rejected submission', d => d.reject(new Error('boom'))],
			]
			it.each(outcomes)('%s does not cancel the deferred autosave', async (_name, settleSubmit) => {
				const d = pendingSubmit()
				c.debouncedSave()
				const submit = c.confirmRequest()
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)

				settleSubmit(d)
				await submit
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('does not cancel a pending timer when the submission fails', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockImplementation((payload: any) =>
					payload?.status === 1 ? Promise.resolve({ success: false, message: 'bad' }) : Promise.resolve({ success: true }))
				c.debouncedSave()
				await c.confirmRequest()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})
		})

		describe('drag availability signals', () => {
			it('hasLoading is false while an autosave is executing, so dragging stays enabled', async () => {
				const inflight = deferred<unknown>()
				h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(inflight.promise)
				c.debouncedSave()
				await fireDebounce()
				expect(c.isAutoSaving.value).toBe(true)
				expect(c.hasLoading.value).toBe(false)
				inflight.resolve({ success: true })
				await settle()
			})

			it('hasLoading is true during request submission (drag locked)', async () => {
				const d = pendingSubmit()
				const submit = c.confirmRequest()
				await settle()
				expect(c.hasLoading.value).toBe(true)
				d.resolve({ success: true })
				await submit
				expect(c.hasLoading.value).toBe(false)
			})
		})

		describe('unmount cleanup', () => {
			it('flushes a pending timer when nothing is active', async () => {
				c.debouncedSave()
				lastWrapper.unmount()
				await settle()
				expect(autosaveCalls()).toHaveLength(1)
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1) // timer was consumed, no second save
			})

			it('clears the timer and deferred flag when a request-level mutation is active', async () => {
				const d = pendingSubmit()
				c.debouncedSave()
				const submit = c.confirmRequest()
				await settle()
				lastWrapper.unmount()
				d.resolve({ success: false, message: 'x' })
				await submit
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
			})

			it('does not flush while an autosave is already executing', async () => {
				const inflight = deferred<unknown>()
				h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(inflight.promise)
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				c.debouncedSave() // newer timer pending while the first save is in flight
				lastWrapper.unmount()
				inflight.resolve({ success: true })
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('never flushes against a different selected file', async () => {
				c.debouncedSave()
				h_.files.selectedFileId = 99
				lastWrapper.unmount()
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
			})

			it('does nothing when no autosave is pending, and is idempotent', async () => {
				lastWrapper.unmount()
				await settle()
				expect(autosaveCalls()).toHaveLength(0)

				const again = setup()
				again.debouncedSave()
				lastWrapper.unmount()
				lastWrapper.unmount()
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})
		})
	})
	describe('per-file autosave intents (WS4 corrections)', () => {
		type Doc = { signers: Array<{ localKey: string }>, signatureFlow: string }
		let docs: Record<number, Doc>

		const autosaveCalls = () => h_.files.saveOrUpdateSignatureRequest.mock.calls
			.map(([payload]: any[]) => payload)
			.filter((payload: any) => payload && 'signatureFlow' in payload)
		const settle = () => vi.advanceTimersByTimeAsync(0)
		const fireDebounce = () => vi.advanceTimersByTimeAsync(1000)
		const keys = (payload: any) => payload.signers.map((s: any) => s.localKey)

		function pendingStatusOne() {
			const d = deferred<unknown>()
			h_.files.saveOrUpdateSignatureRequest.mockImplementation((payload: any) =>
				payload?.status === 1 ? d.promise : Promise.resolve({ success: true }))
			return d
		}

		beforeEach(() => {
			vi.useFakeTimers()
			docs = {
				10: { signers: [{ localKey: 'a1' }, { localKey: 'a2' }], signatureFlow: 'ordered_numeric' },
				20: { signers: [{ localKey: 'b1' }, { localKey: 'b2' }], signatureFlow: 'ordered_numeric' },
			}
			h_.files.getFile.mockImplementation(() => docs[h_.files.selectedFileId] ?? { signers: [] })
			h_.stateCache.isOrderedNumeric = ref(true)
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		describe('file switching', () => {
			it('A -> B while A\'s debounce is pending: B is never submitted', async () => {
				c.debouncedSave()
				h_.files.selectedFileId = 20
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
				expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()
			})

			it('A -> B -> A before the timer fires: A is saved once with its latest list', async () => {
				c.debouncedSave()
				docs[10].signers = [{ localKey: 'a2' }, { localKey: 'a1' }] // latest local reorder
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await fireDebounce()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				expect(keys(autosaveCalls()[0])).toEqual(['a2', 'a1'])
			})

			it('A\'s intent survives switching to B and is saved when returning to A', async () => {
				c.debouncedSave()
				h_.files.selectedFileId = 20
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				expect(keys(autosaveCalls()[0])).toEqual(['a1', 'a2'])
			})

			it('keeps independent pending intents for A and B', async () => {
				c.debouncedSave()
				h_.files.selectedFileId = 20
				await settle()
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				expect(keys(autosaveCalls()[0])).toEqual(['b1', 'b2'])
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
				expect(keys(autosaveCalls()[1])).toEqual(['a1', 'a2'])
			})

			it('selection id 0 never triggers a request', async () => {
				h_.files.selectedFileId = 0
				c.debouncedSave() // records nothing
				await fireDebounce()
				expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()

				h_.files.selectedFileId = 10
				c.debouncedSave()
				h_.files.selectedFileId = 0 // e.g. selectFile() / dashboard
				await fireDebounce()
				expect(h_.files.saveOrUpdateSignatureRequest).not.toHaveBeenCalled()
			})

			it('does not create duplicate timers or requests for repeated intents and switches', async () => {
				c.debouncedSave()
				c.debouncedSave()
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				c.debouncedSave()
				await fireDebounce()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})
		})

		describe('completion semantics', () => {
			it('a failed autosave retains its intent and does not retry in a loop', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockRejectedValueOnce(new Error('boom'))
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				expect(h_.showError).toHaveBeenCalledTimes(1)
				await vi.advanceTimersByTimeAsync(10000)
				expect(autosaveCalls()).toHaveLength(1)
				expect(c.isAutoSaving.value).toBe(false)
			})

			it('a retained intent is retried by a later reselection, without a new reorder', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockRejectedValueOnce(new Error('boom'))
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)

				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
				// that retry succeeded, so the intent is gone: another cycle sends nothing
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
			})

			it('a resolved { success: false } retains the intent, shows no new toast and does not loop', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockResolvedValueOnce({ success: false, message: 'bad' })
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				expect(h_.showError).not.toHaveBeenCalled() // resolved failures were never toasted; unchanged
				await vi.advanceTimersByTimeAsync(10000)
				expect(autosaveCalls()).toHaveLength(1)
				expect(c.isAutoSaving.value).toBe(false)
			})

			it('a resolved { success: false } is retried after switching away and back', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockResolvedValueOnce({ success: false, message: 'bad' })
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
				expect(keys(autosaveCalls()[1])).toEqual(['a1', 'a2'])
			})

			it('a successful autosave removes its matching intent (nothing re-sent on reselection)', async () => {
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('an older request that resolves { success: false } cannot remove or overwrite a newer intent', async () => {
				const first = deferred<unknown>()
				h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(first.promise)
				c.debouncedSave()
				await fireDebounce() // call 1 in flight
				c.debouncedSave() // newer intent
				first.resolve({ success: false, message: 'bad' })
				await settle()
				await fireDebounce()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2) // the newer intent is still saved, once
				// and, having succeeded, it is gone
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
			})

			it('a rejected autosave still toasts once and retains the intent (unchanged)', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockRejectedValueOnce(new Error('boom'))
				c.debouncedSave()
				await fireDebounce()
				expect(h_.showError).toHaveBeenCalledTimes(1)
				h_.files.selectedFileId = 20
				await settle()
				h_.files.selectedFileId = 10
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
			})

			it('a retained intent is retried by a later reorder', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockRejectedValueOnce(new Error('boom'))
				c.debouncedSave()
				await fireDebounce()
				c.debouncedSave()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
			})

			it('a newer intent recorded during an in-flight autosave is not cleared by the older completion', async () => {
				const first = deferred<unknown>()
				h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(first.promise)
				c.debouncedSave()
				await fireDebounce() // call 1 in flight
				expect(autosaveCalls()).toHaveLength(1)
				c.debouncedSave() // newer reorder while call 1 is in flight
				first.resolve({ success: true })
				await settle()
				await fireDebounce()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2) // exactly one follow-up, never concurrent
				expect(c.isAutoSaving.value).toBe(false)
			})

			it('the follow-up for a mid-flight intent is not sent if the user switched away', async () => {
				const first = deferred<unknown>()
				h_.files.saveOrUpdateSignatureRequest.mockReturnValueOnce(first.promise)
				c.debouncedSave()
				await fireDebounce()
				c.debouncedSave()
				h_.files.selectedFileId = 20
				first.resolve({ success: true })
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
				h_.files.selectedFileId = 10 // intent retained: saved on return
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(2)
			})
		})

		describe('confirmRequestSigner', () => {
			const signer = { localKey: 'a2', signRequestId: 5 } as never
			const activationCalls = () => h_.files.saveOrUpdateSignatureRequest.mock.calls
				.map(([payload]: any[]) => payload)
				.filter((payload: any) => payload?.status === 1)

			async function activate() {
				c.requestSignatureForSigner(signer)
				return c.confirmRequestSigner()
			}

			it('successful activation discards the covered deferred intent', async () => {
				const d = pendingStatusOne()
				c.debouncedSave()
				c.requestSignatureForSigner(signer)
				const run = c.confirmRequestSigner()
				await settle()
				await fireDebounce() // fires while hasLoading: deferred
				expect(autosaveCalls()).toHaveLength(0)
				d.resolve({ success: true })
				await run
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
				expect(activationCalls()).toHaveLength(1)
			})

			it('successful activation discards a still-pending timer', async () => {
				c.debouncedSave()
				await activate()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(0)
			})

			it('a newer intent recorded after serialization survives a successful activation', async () => {
				const d = pendingStatusOne()
				c.debouncedSave()
				c.requestSignatureForSigner(signer)
				const run = c.confirmRequestSigner()
				await settle()
				c.debouncedSave()
				d.resolve({ success: true })
				await run
				await settle()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('{success:false} preserves the intent and keeps today\'s toast and dialog behaviour', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockImplementation((payload: any) =>
					Promise.resolve(payload?.status === 1 ? { success: false, message: 'bad' } : { success: true }))
				c.debouncedSave()
				await activate()
				expect(h_.showSuccess).toHaveBeenCalledTimes(1) // existing behaviour, deliberately unchanged
				expect(c.showConfirmRequestSigner.value).toBe(false)
				expect(c.selectedSigner.value).toBeNull()
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('a rejected activation preserves the intent and keeps the dialog open', async () => {
				h_.files.saveOrUpdateSignatureRequest.mockImplementation((payload: any) =>
					payload?.status === 1 ? Promise.reject(new Error('boom')) : Promise.resolve({ success: true }))
				c.debouncedSave()
				await activate()
				expect(h_.showSuccess).not.toHaveBeenCalled()
				expect(h_.showError).toHaveBeenCalledTimes(1)
				expect(c.showConfirmRequestSigner.value).toBe(true)
				await fireDebounce()
				expect(autosaveCalls()).toHaveLength(1)
			})

			it('keeps the activation payload and success handling unchanged', async () => {
				await activate()
				expect(activationCalls()).toHaveLength(1)
				expect(activationCalls()[0]).toEqual({ signers: [], status: 1 })
				expect(h_.showSuccess).toHaveBeenCalledTimes(1)
				expect(c.showConfirmRequestSigner.value).toBe(false)
				expect(c.hasLoading.value).toBe(false)
			})
		})
	})
})
