/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Signature-overlay contract for the Preview tab: placed fields group onto
 * the correct document-local page, scale correctly against the rendered
 * page width, resolve signer labels/colours, skip malformed elements
 * safely, and rescale on a displayWidth change without a PDF re-fetch.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p: string) => p }))

// usePdfPagePreview is mocked so these tests exercise only the overlay logic
// (elementsByPage/boxesFor/signer colour+label resolution) against a known
// pages/displayWidth, independent of the PDF fetch/render pipeline — which
// has its own dedicated lifecycle coverage in usePdfPagePreview.spec.ts.
const state = vi.hoisted(() => ({ mock: undefined as unknown }))
vi.mock('../../../composables/usePdfPagePreview', async () => {
	const { ref } = await import('vue')
	const mock = {
		status: ref('ready'),
		pages: ref([
			{ pageNumber: 1, pointWidth: 600, pointHeight: 800 },
			{ pageNumber: 2, pointWidth: 600, pointHeight: 800 },
		]),
		displayWidth: ref(300),
		containerEl: ref(null),
		setCanvasRef: vi.fn(),
		measure: vi.fn(),
		render: vi.fn(),
		reload: vi.fn(),
		destroy: vi.fn(),
	}
	state.mock = mock
	return { usePdfPagePreview: () => mock }
})

import PwDocumentInlinePreview from '../../../components/PublicWorkflow/PwDocumentInlinePreview.vue'

type MockRefs = {
	status: { value: string }
	pages: { value: Array<{ pageNumber: number, pointWidth: number, pointHeight: number }> }
	displayWidth: { value: number }
	render: ReturnType<typeof vi.fn>
	reload: ReturnType<typeof vi.fn>
}
function mockRefs(): MockRefs {
	return state.mock as MockRefs
}

function mountPreview(document: unknown, active = true) {
	return mount(PwDocumentInlinePreview, {
		props: { active, document: document as import('../../../services/publicWorkflow').WorkflowDocument | null },
		global: { stubs: { NcIconSvgWrapper: true, NcLoadingIcon: true } },
	})
}

const document = {
	signers: [
		{ signRequestId: 101, displayName: 'Asha' },
		{ signRequestId: 202, identifyMethods: [{ method: 'email', value: 'dan@example.com' }] },
	],
	visibleElements: [
		// Page 1, signer 101 — a normal, valid field.
		{ type: 'signature', elementId: 1, fileId: 1, signRequestId: 101, coordinates: { page: 1, left: 50, top: 60, width: 120, height: 40 } },
		// Page 2, signer 202 — a normal, valid field on a different page.
		{ type: 'signature', elementId: 2, fileId: 1, signRequestId: 202, coordinates: { page: 2, left: 10, top: 20, width: 80, height: 30 } },
		// Wrong type: normaliseVisibleElement rejects non-signature elements.
		{ type: 'initial', elementId: 3, fileId: 1, signRequestId: 101, coordinates: { page: 1, left: 0, top: 0, width: 10, height: 10 } },
		// Missing width/height: passes normalisation but has nothing to size a box with.
		{ type: 'signature', elementId: 4, fileId: 1, signRequestId: 101, coordinates: { page: 1, left: 5, top: 5 } },
		// Non-finite coordinate: normaliseVisibleElement rejects it outright.
		{ type: 'signature', elementId: 5, fileId: 1, signRequestId: 101, coordinates: { page: 'not-a-number', left: 1, top: 1, width: 1, height: 1 } },
	],
}

describe('PwDocumentInlinePreview.vue — signature overlay', () => {
	beforeEach(() => {
		const m = mockRefs()
		m.status.value = 'ready'
		m.displayWidth.value = 300
		m.pages.value = [
			{ pageNumber: 1, pointWidth: 600, pointHeight: 800 },
			{ pageNumber: 2, pointWidth: 600, pointHeight: 800 },
		]
		m.render.mockClear()
		m.reload.mockClear()
	})

	it('groups elements by their document-local page and skips malformed ones', () => {
		const w = mountPreview(document)
		const pageWraps = w.findAll('.pdf-page-preview__page-wrap')
		expect(pageWraps).toHaveLength(2)
		// Only the one valid field per page survives — the wrong-type, the
		// missing-size, and the non-finite-coordinate entries are all dropped.
		expect(pageWraps[0].findAll('.pdf-page-preview__field')).toHaveLength(1)
		expect(pageWraps[1].findAll('.pdf-page-preview__field')).toHaveLength(1)
	})

	it('scales box geometry against displayWidth / page point-width', () => {
		const w = mountPreview(document)
		const field = w.findAll('.pdf-page-preview__page-wrap')[0].find('.pdf-page-preview__field')
		// cssScale = displayWidth(300) / pointWidth(600) = 0.5
		const style = field.attributes('style') ?? ''
		expect(style).toContain('left: 25px')
		expect(style).toContain('top: 30px')
		expect(style).toContain('width: 60px')
		expect(style).toContain('height: 20px')
	})

	it('resolves signer labels from displayName, then identifyMethods', () => {
		const w = mountPreview(document)
		const page1Field = w.findAll('.pdf-page-preview__page-wrap')[0].find('.pdf-page-preview__field')
		const page2Field = w.findAll('.pdf-page-preview__page-wrap')[1].find('.pdf-page-preview__field')
		expect(page1Field.find('.pdf-page-preview__field-who').text()).toContain('Asha')
		expect(page2Field.find('.pdf-page-preview__field-who').text()).toContain('dan@example.com')
	})

	it('assigns distinct, stable colours per signer', () => {
		const w = mountPreview(document)
		const page1Style = w.findAll('.pdf-page-preview__page-wrap')[0].find('.pdf-page-preview__field').attributes('style') ?? ''
		const page2Style = w.findAll('.pdf-page-preview__page-wrap')[1].find('.pdf-page-preview__field').attributes('style') ?? ''
		const colorOf = (style: string) => /--pw-field-border:\s*([^;]+);/.exec(style)?.[1]
		expect(colorOf(page1Style)).toBeTruthy()
		expect(colorOf(page2Style)).toBeTruthy()
		expect(colorOf(page1Style)).not.toBe(colorOf(page2Style))
	})

	it('handles a null document and an empty visibleElements list without boxes or errors', () => {
		const w1 = mountPreview(null)
		expect(w1.findAll('.pdf-page-preview__field')).toHaveLength(0)
		const w2 = mountPreview({ signers: [], visibleElements: [] })
		expect(w2.findAll('.pdf-page-preview__field')).toHaveLength(0)
	})

	it('rescales overlay geometry on a displayWidth change without calling render/reload again', async () => {
		const w = mountPreview(document)
		const m = mockRefs()
		m.render.mockClear()
		m.displayWidth.value = 600 // simulate a resize (cssScale now 1.0)
		await w.vm.$nextTick()
		const field = w.findAll('.pdf-page-preview__page-wrap')[0].find('.pdf-page-preview__field')
		const style = field.attributes('style') ?? ''
		expect(style).toContain('left: 50px')
		expect(style).toContain('top: 60px')
		expect(m.render).not.toHaveBeenCalled()
		expect(m.reload).not.toHaveBeenCalled()
	})

	it('triggers render() only on first activation, not on every truthy transition while already loaded', async () => {
		const m = mockRefs()
		m.status.value = 'idle'
		const w = mountPreview(document, false)
		expect(m.render).not.toHaveBeenCalled()
		m.status.value = 'ready' // simulate pages already loaded from a prior activation
		await w.setProps({ active: true })
		expect(m.render).not.toHaveBeenCalled()
	})
})
