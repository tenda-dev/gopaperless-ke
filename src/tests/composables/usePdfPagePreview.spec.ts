/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lifecycle contracts for the public-workflow PDF preview composable: a
 * newer render pass must win over a slower, superseded one (no stale draw
 * landing on a canvas a later pass now owns), a resize mid-pass must not mix
 * page scales, and destroy() must stop a pending pass from reviving state
 * after the consumer is gone.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

type Deferred<T> = { promise: Promise<T>, resolve: (v: T) => void, reject: (e: unknown) => void }
function deferred<T>(): Deferred<T> {
	let resolve!: (v: T) => void
	let reject!: (e: unknown) => void
	const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej })
	return { promise, resolve, reject }
}

/** A fake pdf.js RenderTask. cancel() optionally rejects its own promise, as a
 *  real RenderingCancelledException would. */
function makeRenderTask(cancelRejects = false) {
	const d = deferred<void>()
	const cancel = vi.fn(() => {
		if (cancelRejects) { d.reject(new Error('RenderingCancelledException')) }
	})
	return { task: { promise: d.promise, cancel }, resolve: d.resolve, cancel }
}

function makePageProxy(pointWidth: number, pointHeight: number, task: { promise: Promise<void>, cancel: () => void }) {
	return {
		getViewport: vi.fn(({ scale }: { scale: number }) => ({ width: pointWidth * scale, height: pointHeight * scale })),
		render: vi.fn(() => task),
	}
}

/** A fake pdf.js PDFDocumentProxy — numPages/getPage plus the destroy() the
 *  composable calls when a document is superseded or released. */
function makeDoc(numPages: number, getPage: ReturnType<typeof vi.fn>, destroy?: ReturnType<typeof vi.fn>) {
	return { numPages, getPage, destroy: destroy ?? vi.fn(async () => {}) }
}

function okResponse() {
	return { status: 200, ok: true, headers: { get: () => 'application/pdf' }, arrayBuffer: async () => new ArrayBuffer(0) }
}

/** Poll with real timers until `check` stops throwing — needed because the
 *  mocked worker-URL dynamic import resolves via a real event-loop tick, not
 *  a plain microtask, so a hand-rolled `await Promise.resolve()` chain never
 *  catches up to it. */
async function waitUntil(check: () => void): Promise<void> {
	await vi.waitFor(check, { timeout: 3000, interval: 10 })
}

/** A getPage() that attaches the (fake) canvas for that page the moment pdf.js
 *  reports it — same causal order the real template's ref callback gives:
 *  the canvas exists before renderCanvases() ever looks for it, deterministically,
 *  with no reliance on timing a poll against render()'s internal await points. */
function pageGetter(proxies: Array<ReturnType<typeof makePageProxy>>, setCanvasRef: (n: number, el: Element | null) => void) {
	return vi.fn(async (i: number) => {
		setCanvasRef(i, document.createElement('canvas'))
		return proxies[i - 1]
	})
}

vi.mock('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url', () => ({ default: 'worker-url' }))

describe('usePdfPagePreview', () => {
	let getDocumentMock: ReturnType<typeof vi.fn>

	beforeEach(() => {
		vi.resetModules()
		HTMLCanvasElement.prototype.getContext = vi.fn(() => ({})) as unknown as typeof HTMLCanvasElement.prototype.getContext
		getDocumentMock = vi.fn()
		vi.doMock('pdfjs-dist', () => ({ getDocument: getDocumentMock, GlobalWorkerOptions: {} }))
		vi.stubGlobal('fetch', vi.fn())
	})
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.doUnmock('pdfjs-dist')
	})

	it('loads pages and reaches ready, independent of the canvas draw still being in flight', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		const p = usePdfPagePreview('/x')
		p.containerEl.value = { clientWidth: 480 } as unknown as HTMLElement

		const rt = makeRenderTask()
		const proxy = makePageProxy(600, 800, rt.task)
		const doc = makeDoc(1, pageGetter([proxy], p.setCanvasRef))
		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(okResponse())
		getDocumentMock.mockReturnValue({ promise: Promise.resolve(doc) })

		const pending = p.render()
		await waitUntil(() => { if (p.pages.value.length === 0) { throw new Error('not yet') } })
		expect(p.pages.value).toEqual([{ pageNumber: 1, pointWidth: 600, pointHeight: 800 }])
		expect(p.status.value).toBe('ready') // page metadata known before the draw itself finishes
		await waitUntil(() => { if (proxy.render.mock.calls.length === 0) { throw new Error('not yet') } })

		rt.resolve()
		await pending
		expect(p.status.value).toBe('ready')
	})

	it('a newer render pass cancels the previous pass\'s in-flight draw, and the cancelled pass cannot override the newer one\'s status', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		const p = usePdfPagePreview('/x')
		p.containerEl.value = { clientWidth: 480 } as unknown as HTMLElement

		const rt1 = makeRenderTask(true)
		const rt2 = makeRenderTask()
		const proxy1 = makePageProxy(600, 800, rt1.task)
		const proxy2 = makePageProxy(600, 800, rt2.task)
		const doc1 = makeDoc(1, pageGetter([proxy1], p.setCanvasRef))
		const doc2 = makeDoc(1, pageGetter([proxy2], p.setCanvasRef))
		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(okResponse())
		getDocumentMock
			.mockReturnValueOnce({ promise: Promise.resolve(doc1) })
			.mockReturnValueOnce({ promise: Promise.resolve(doc2) })

		const first = p.render()
		// Wait until the first pass has actually started drawing (not merely
		// loaded page metadata) before superseding it.
		await waitUntil(() => { if (proxy1.render.mock.calls.length === 0) { throw new Error('not yet') } })

		const second = p.render()
		// Starting a new pass must cancel the first pass's active draw.
		expect(rt1.cancel).toHaveBeenCalledTimes(1)

		await waitUntil(() => { if (proxy2.render.mock.calls.length === 0) { throw new Error('not yet') } })
		expect(doc1.destroy).toHaveBeenCalledTimes(1)
		rt2.resolve()
		await Promise.all([first, second])

		// The superseded pass's cancellation-triggered rejection must not flip
		// status away from what the newer, winning pass established.
		expect(p.status.value).toBe('ready')
	})

	it('destroy() cancels the active draw and a late resolution cannot revive stale state', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		const p = usePdfPagePreview('/x')
		p.containerEl.value = { clientWidth: 480 } as unknown as HTMLElement

		const rt = makeRenderTask(true)
		const proxy = makePageProxy(600, 800, rt.task)
		const doc = makeDoc(1, pageGetter([proxy], p.setCanvasRef))
		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(okResponse())
		getDocumentMock.mockReturnValue({ promise: Promise.resolve(doc) })

		const pending = p.render()
		await waitUntil(() => { if (proxy.render.mock.calls.length === 0) { throw new Error('not yet') } })

		p.destroy()
		expect(rt.cancel).toHaveBeenCalledTimes(1)
		expect(doc.destroy).toHaveBeenCalledTimes(1)
		await pending

		// Page metadata was already known (status flips to 'ready' as soon as
		// pages load, before the draw phase even starts), so 'ready' persisting
		// is correct here. What must NOT happen is the cancellation-triggered
		// rejection surfacing as 'error' once the consumer is gone.
		expect(p.status.value).not.toBe('error')
	})

	it('measure() derives displayWidth from the container, with a floor and a safe default', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		const p = usePdfPagePreview('/x')

		p.containerEl.value = { clientWidth: 150 } as unknown as HTMLElement
		p.measure()
		expect(p.displayWidth.value).toBe(220) // floored

		p.containerEl.value = { clientWidth: 0 } as unknown as HTMLElement
		p.measure()
		expect(p.displayWidth.value).toBe(360) // falls back when hidden (display:none -> clientWidth 0)

		p.containerEl.value = { clientWidth: 900 } as unknown as HTMLElement
		p.measure()
		p.containerEl.value = null
		p.measure()
		expect(p.displayWidth.value).toBe(900) // no container: last measured value kept, no throw
	})

	it('a 404 response yields "unavailable", a non-ok/JSON response yields "error"', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ status: 404, ok: false, headers: { get: () => '' } })
		const p1 = usePdfPagePreview('/x')
		await p1.render()
		expect(p1.status.value).toBe('unavailable')

		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ status: 200, ok: true, headers: { get: () => 'application/json' } })
		const p2 = usePdfPagePreview('/y')
		await p2.render()
		expect(p2.status.value).toBe('error')
	})

	it('a resize mid-render does not mix page scales within the same pass', async () => {
		const { usePdfPagePreview } = await import('../../composables/usePdfPagePreview')
		const p = usePdfPagePreview('/x')
		p.containerEl.value = { clientWidth: 480 } as unknown as HTMLElement

		const rt1 = makeRenderTask()
		const rt2 = makeRenderTask()
		const proxy1 = makePageProxy(600, 800, rt1.task)
		const proxy2 = makePageProxy(300, 800, rt2.task) // different pointWidth on purpose
		const proxies = [proxy1, proxy2]
		const doc = { numPages: 2, getPage: pageGetter(proxies, p.setCanvasRef) }
		;(global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(okResponse())
		getDocumentMock.mockReturnValue({ promise: Promise.resolve(doc) })

		const pending = p.render()
		// Page 1's draw has started (scale already captured via getViewport).
		await waitUntil(() => { if (proxy1.render.mock.calls.length === 0) { throw new Error('not yet') } })
		// Resize the container before page 2 is drawn.
		p.containerEl.value = { clientWidth: 960 } as unknown as HTMLElement
		p.measure()

		rt1.resolve()
		await waitUntil(() => { if (proxy2.render.mock.calls.length === 0) { throw new Error('not yet') } })
		rt2.resolve()
		await pending

		// The draw call's scale (not the scale:1 metadata call) is the last
		// recorded call on each proxy's getViewport. scale = widthAtStart /
		// pointWidth * ratio, so scale * pointWidth recovers widthAtStart * ratio
		// — the invariant that must match across both pages of one pass.
		const draw1 = proxy1.getViewport.mock.calls.at(-1)![0].scale
		const draw2 = proxy2.getViewport.mock.calls.at(-1)![0].scale
		// Both pages must be scaled against the SAME displayWidth snapshot
		// (480, pre-resize) — not a mix of pre- and post-resize widths.
		expect(draw1 * 600).toBeCloseTo(draw2 * 300, 10)
	})
})
