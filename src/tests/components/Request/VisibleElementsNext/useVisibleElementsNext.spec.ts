/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, beforeEach, vi } from 'vitest'
import { createL10nMock } from '../../../testHelpers/l10n.js'
import { createPinia, setActivePinia } from 'pinia'
import { showError } from '@/services/toast.ts'

// Every write to the global files-store `loading` flag is recorded: WS5 requires the
// editor initialization to never touch it.
const loadingWrites: boolean[] = []

const filesStoreMock = {
	get loading() {
		return false
	},
	set loading(value: boolean) {
		loadingWrites.push(value)
	},
	getFile: vi.fn(() => ({})),
	getEditableFile: vi.fn((): Record<string, any> => ({
		id: 1,
		name: 'test.pdf',
		status: 0,
		signers: [] as Array<{ signRequestId: number; displayName: string }>,
		visibleElements: [],
	})),
	saveOrUpdateSignatureRequest: vi.fn(),
}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((_app: string, key: string) => key === 'can_request_sign'),
}))

vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: vi.fn(() => ({
		libresign: {
			config: {
				'sign-elements': {
					'is-available': true,
					'full-signature-width': 200,
					'full-signature-height': 100,
				},
			},
		},
	})),
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		delete: vi.fn(),
	},
}))
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => path),
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/event-bus', () => ({
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))

vi.mock('@/services/toast.ts', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('../../../../store/files.js', () => ({
	useFilesStore: vi.fn(() => filesStoreMock),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

describe('useVisibleElementsNext', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		loadingWrites.length = 0
		filesStoreMock.getEditableFile.mockReturnValue({
			id: 1,
			name: 'test.pdf',
			status: 0,
			signers: [],
			visibleElements: [],
		})
	})

	it('initializes with default state', async () => {
		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.modal.value).toBe(false)
		expect(state.loading.value).toBe(false)
		expect(state.pdfReady.value).toBe(false)
		expect(state.mode.value).toBe('browse')
		expect(state.focusedSignerId.value).toBeNull()
		expect(state.placing.value).toBe(false)
	})

	it('computes signer count from document signers', async () => {
		filesStoreMock.getEditableFile.mockReturnValue({
			id: 1,
			name: 'test.pdf',
			status: 0,
			signers: [
				{ signRequestId: 1, displayName: 'Alice' },
				{ signRequestId: 2, displayName: 'Bob' },
			],
			visibleElements: [],
		})

		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.signerCount.value).toBe(2)
		expect(state.signerList.value).toHaveLength(2)
	})

	it('canSave is true for draft documents', async () => {
		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.canSave.value).toBe(true)
	})
	describe('modal initialization lifecycle (WS5)', () => {
		type Deferred<T> = { promise: Promise<T>, resolve: (value: T) => void, reject: (reason?: unknown) => void }
		const deferred = <T = unknown>(): Deferred<T> => {
			let resolve!: (value: T) => void
			let reject!: (reason?: unknown) => void
			const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej })
			return { promise, resolve, reject }
		}
		const okPdf = () => ({
			ok: true,
			headers: { get: () => 'application/pdf' },
			blob: async () => new Blob(['%PDF'], { type: 'application/pdf' }),
		})
		const element = {
			type: 'signature',
			elementId: 5,
			fileId: 1,
			signRequestId: 11,
			coordinates: { page: 1, left: 10, top: 10, width: 50, height: 20 },
		}
		// Document that already carries a renderable PDF: fetchFiles is skipped.
		const docWithPdf = (id = 1) => ({
			id,
			name: `doc-${id}`,
			status: 0,
			file: `/pdf/${id}.pdf`,
			metadata: { p: 2, extension: 'pdf' },
			signers: [{ signRequestId: 11, displayName: 'Alice' }],
			visibleElements: [{ ...element, fileId: id }],
		}) as Record<string, any>
		// Document without any renderable file: fetchFiles is required.
		const docWithoutFiles = (id: number) => ({
			id,
			name: `doc-${id}`,
			status: 0,
			signers: [{ signRequestId: 11, displayName: 'Alice' }],
			visibleElements: [],
		}) as Record<string, any>
		const childFilesResponse = (id: number) => ({
			data: { ocs: { data: { data: [{ id, name: `child-${id}`, file: `/pdf/${id}.pdf`, metadata: { p: 1, extension: 'pdf' } }] } } },
		})

		const fetchMock = vi.fn()
		const axiosGet = axios.get as unknown as ReturnType<typeof vi.fn>
		const selected = { doc: docWithPdf() as Record<string, any> }

		const load = async () => {
			const { useVisibleElementsNext } = await import(
				'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
			)
			return useVisibleElementsNext()
		}

		beforeEach(() => {
			fetchMock.mockReset()
			vi.stubGlobal('fetch', fetchMock)
			axiosGet.mockReset()
			selected.doc = docWithPdf()
			filesStoreMock.getEditableFile.mockImplementation(() => selected.doc)
		})

		it('holds local initializing until mandatory work is done, then exposes seeded state', async () => {
			const gate = deferred()
			fetchMock.mockImplementation(() => gate.promise)
			const state = await load()

			const opening = state.showModal()

			expect(state.modal.value).toBe(true)
			expect(state.initializing.value).toBe(true)
			expect(state.pdfEditorFiles.value).toHaveLength(0)
			expect(state.countBySigner.value).toEqual({})

			gate.resolve(okPdf())
			await opening

			expect(state.initializing.value).toBe(false)
			expect(state.modal.value).toBe(true)
			expect(state.pdfEditorFiles.value).toHaveLength(1)
			// page map built and placements seeded from the server visibleElements
			expect(state.countBySigner.value).toEqual({ 11: 1 })
			// signer colours are registered synchronously as part of init
			expect(Object.keys(state.colorMap.value)).toEqual(['11'])
			// the editor gate is a separate, later step
			expect(state.pdfReady.value).toBe(false)
			await state.handlePdfReady()
			expect(state.pdfReady.value).toBe(true)
		})

		it('never writes the global files-store loading flag', async () => {
			fetchMock.mockResolvedValue(okPdf())
			const state = await load()

			await state.showModal()
			state.closeModal()

			fetchMock.mockRejectedValue(new Error('network'))
			await state.showModal()

			expect(loadingWrites).toEqual([])
		})

		it('clears initializing, reports the error and closes the modal when the PDF request fails', async () => {
			fetchMock.mockRejectedValue(new Error('network'))
			const state = await load()

			await state.showModal()

			expect(state.initializing.value).toBe(false)
			expect(state.modal.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(0)
			expect(showError).toHaveBeenCalledWith('An error occurred')
		})

		it('fails initialization and closes the modal when there is no renderable PDF URL', async () => {
			selected.doc = docWithoutFiles(1)
			axiosGet.mockResolvedValue({ data: { ocs: { data: { data: [] } } } })
			const state = await load()

			await state.showModal()

			expect(fetchMock).not.toHaveBeenCalled()
			expect(state.initializing.value).toBe(false)
			expect(state.modal.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(0)
			expect(showError).toHaveBeenCalledWith('No PDF is available for this document')
			expect(loadingWrites).toEqual([])
		})

		it.each([
			['404 without a body', { status: 404, ok: false, json: undefined, contentType: 'text/html' }, 'Document not found'],
			['403 forbidden', { status: 403, ok: false, json: undefined, contentType: 'text/html' }, 'You do not have permission to view this document'],
			['500 server error', { status: 500, ok: false, json: undefined, contentType: 'text/html' }, 'Could not load the document. Please try again.'],
			['an OK response carrying JSON', { status: 200, ok: true, json: undefined, contentType: 'application/json' }, 'Document not found'],
			['an OCS message in the body', { status: 500, ok: false, json: { ocs: { data: { message: 'Storage unavailable' } } }, contentType: 'application/json' }, 'Storage unavailable'],
		])('maps a failed PDF response (%s) to an accurate message and closes the modal', async (_label, spec, expected) => {
			fetchMock.mockResolvedValue({
				ok: spec.ok,
				status: spec.status,
				headers: { get: () => spec.contentType },
				json: async () => {
					if (spec.json === undefined) {
						throw new SyntaxError('not json')
					}
					return spec.json
				},
				blob: async () => new Blob(),
			})
			const state = await load()

			await state.showModal()

			expect(state.initializing.value).toBe(false)
			expect(state.modal.value).toBe(false)
			expect(showError).toHaveBeenCalledTimes(1)
			expect(showError).toHaveBeenCalledWith(expected)
		})

		it('surfaces the OCS message when fetching the child files fails', async () => {
			selected.doc = docWithoutFiles(1)
			axiosGet.mockRejectedValue({ response: { data: { ocs: { data: { message: 'Not allowed' } } } } })
			const state = await load()

			await state.showModal()

			expect(state.initializing.value).toBe(false)
			expect(state.modal.value).toBe(false)
			expect(showError).toHaveBeenCalledWith('Not allowed')
		})

		it('does not mutate state when the modal is closed during initialization', async () => {
			const gate = deferred()
			fetchMock.mockImplementation(() => gate.promise)
			const state = await load()

			const opening = state.showModal()
			state.closeModal()
			expect(state.initializing.value).toBe(false)
			gate.resolve(okPdf())
			await opening

			expect(state.modal.value).toBe(false)
			expect(state.initializing.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(0)
			expect(state.countBySigner.value).toEqual({})
			expect(Object.keys(state.colorMap.value)).toEqual([])
			expect(showError).not.toHaveBeenCalled()
		})

		it('does not report a late failure of a superseded initialization', async () => {
			const gate = deferred()
			fetchMock.mockImplementation(() => gate.promise)
			const state = await load()

			const opening = state.showModal()
			state.closeModal()
			gate.reject(new Error('late failure'))
			await opening

			expect(showError).not.toHaveBeenCalled()
			expect(state.modal.value).toBe(false)
		})

		it('reopens cleanly after a failed initialization', async () => {
			fetchMock.mockRejectedValueOnce(new Error('network'))
			const state = await load()
			await state.showModal()
			expect(state.modal.value).toBe(false)

			fetchMock.mockResolvedValue(okPdf())
			await state.showModal()

			expect(state.modal.value).toBe(true)
			expect(state.initializing.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(1)
			expect(state.countBySigner.value).toEqual({ 11: 1 })
		})

		it('a stale initialization cannot clear or populate a newer one', async () => {
			const first = deferred()
			const second = deferred()
			fetchMock.mockImplementationOnce(() => first.promise).mockImplementationOnce(() => second.promise)
			const state = await load()

			const firstOpening = state.showModal()
			state.closeModal()
			const secondOpening = state.showModal()

			first.resolve(okPdf())
			await firstOpening
			expect(state.initializing.value).toBe(true)
			expect(state.pdfEditorFiles.value).toHaveLength(0)

			second.resolve(okPdf())
			await secondOpening
			expect(state.initializing.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(1)
			expect(fetchMock).toHaveBeenCalledTimes(2)
		})

		it('abandons an initialization whose document selection changed, without touching either draft', async () => {
			const docA = docWithoutFiles(1)
			const docB = docWithoutFiles(2)
			selected.doc = docA
			const gate = deferred()
			axiosGet.mockImplementation(() => gate.promise)
			const state = await load()

			const opening = state.showModal()
			selected.doc = docB
			gate.resolve(childFilesResponse(1))
			await opening

			expect(docA.files).toBeUndefined()
			expect(docB.files).toBeUndefined()
			expect(state.modal.value).toBe(false)
			expect(state.initializing.value).toBe(false)
			expect(fetchMock).not.toHaveBeenCalled()
		})

		it('fetchFiles writes only to the document it was requested for', async () => {
			const docA = docWithoutFiles(1)
			const docB = docWithoutFiles(2)
			selected.doc = docA
			const gate = deferred()
			axiosGet.mockImplementation(() => gate.promise)
			const state = await load()

			const fetching = state.fetchFiles()
			selected.doc = docB
			gate.resolve(childFilesResponse(1))
			await fetching

			expect(axiosGet).toHaveBeenCalledTimes(1)
			expect((axiosGet.mock.calls[0][1] as { params: { parentFileId: number } }).params.parentFileId).toBe(1)
			expect(docA.files).toHaveLength(1)
			expect(docB.files).toBeUndefined()
		})

		it('loads child files first when the document has no renderable PDF, then proceeds', async () => {
			selected.doc = docWithoutFiles(1)
			axiosGet.mockResolvedValue(childFilesResponse(1))
			fetchMock.mockResolvedValue(okPdf())
			const state = await load()

			await state.showModal()

			expect(axiosGet).toHaveBeenCalledTimes(1)
			expect(state.initializing.value).toBe(false)
			expect(state.pdfEditorFiles.value).toHaveLength(1)
			expect(loadingWrites).toEqual([])
		})
	})
})
