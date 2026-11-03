/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Shared PDF loading and rendering logic for public workflow previews.
 *
 * Exposes individual pages and their PDF dimensions so consumers can
 * position page-specific overlays without coupling rendering to presentation.
 * Each page is rendered into its own canvas, keeping page geometry available
 * for scaling overlays against the rendered document.
 */
import { nextTick, ref } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'

export type PdfPagePreviewStatus = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'
export type PdfPageInfo = { pageNumber: number, pointWidth: number, pointHeight: number }

type PageProxy = {
	getViewport: (o: { scale: number }) => { width: number, height: number }
	render: (p: Record<string, unknown>) => { promise: Promise<void>, cancel: () => void }
}

// Configure the pdf.js worker once for all preview instances.
let workerConfigured = false
async function ensureWorker(): Promise<void> {
	if (workerConfigured) { return }
	const mod = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')
	;(pdfjsLib as unknown as { GlobalWorkerOptions: { workerSrc: string } }).GlobalWorkerOptions.workerSrc = (mod as { default: string }).default
	workerConfigured = true
}

export function usePdfPagePreview(url: string) {
	const status = ref<PdfPagePreviewStatus>('idle')
	const pages = ref<PdfPageInfo[]>([])
	const displayWidth = ref<number>(480)
	const containerEl = ref<HTMLElement | null>(null)

	const canvasRefs = new Map<number, HTMLCanvasElement>()
	let pageProxies: PageProxy[] = []
	let loadToken = 0
	// The newest loaded document; destroyed when superseded or on destroy(),
	// otherwise every reload() leaks a pdfjs document (memory + worker).
	let currentPdf: { destroy: () => Promise<void> } | null = null
	// The single in-flight pdf.js RenderTask, if any. Cancelled whenever a new
	// render pass starts (reload, or a fresh render()) so a stale draw can't
	// finish after a newer pass has already repainted the same keyed canvas.
	let activeRenderTask: { promise: Promise<void>, cancel: () => void } | null = null
	function cancelActiveRender(): void {
		activeRenderTask?.cancel()
		activeRenderTask = null
	}

	function setCanvasRef(pageNumber: number, el: Element | null): void {
		if (el instanceof HTMLCanvasElement) {
			canvasRefs.set(pageNumber, el)
		} else {
			canvasRefs.delete(pageNumber)
		}
	}

	// Measured against the scroll container, not the page itself, so callers
	// can remeasure on resize without waiting on a render pass.
	function measure(): void {
		const el = containerEl.value
		if (!el) { return }
		displayWidth.value = Math.max(220, el.clientWidth || 360)
	}

	async function renderCanvases(token: number): Promise<void> {
		const ratio = Math.min(window.devicePixelRatio || 1, 2)
		// Snapshot once: a ResizeObserver-driven change to displayWidth mid-pass
		// must not leave this pass's pages at mismatched scales.
		const widthAtStart = displayWidth.value
		for (const info of pages.value) {
			if (token !== loadToken) { return }
			const canvas = canvasRefs.get(info.pageNumber)
			const proxy = pageProxies[info.pageNumber - 1]
			if (!canvas || !proxy) { continue }
			const scale = (widthAtStart / info.pointWidth) * ratio
			const viewport = proxy.getViewport({ scale })
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			canvas.style.width = '100%'
			canvas.style.height = 'auto'
			const ctx = canvas.getContext('2d')
			if (!ctx) { continue }
			const task = proxy.render({ canvas, canvasContext: ctx, viewport })
			activeRenderTask = task
			try {
				await task.promise
			} finally {
				if (activeRenderTask === task) { activeRenderTask = null }
			}
		}
	}

	async function render(): Promise<void> {
		const token = ++loadToken
		cancelActiveRender()
		status.value = 'loading'
		pages.value = []
		canvasRefs.clear()
		pageProxies = []
		try {
			await ensureWorker()
			const res = await fetch(url)
			const contentType = res.headers.get('Content-Type') ?? ''
			if (res.status === 404) { if (token === loadToken) { status.value = 'unavailable' } return }
			if (!res.ok || contentType.includes('application/json')) { if (token === loadToken) { status.value = 'error' } return }
			const data = await res.arrayBuffer()
			if (token !== loadToken) { return }
			const pdf = await pdfjsLib.getDocument({ data }).promise
			if (token !== loadToken) { pdf.destroy(); return }
			currentPdf?.destroy().catch(() => {})
			currentPdf = pdf
			const metas: PdfPageInfo[] = []
			for (let i = 1; i <= pdf.numPages; i++) {
				const page = await pdf.getPage(i)
				if (token !== loadToken) { return }
				const vp = page.getViewport({ scale: 1 })
				pageProxies[i - 1] = page as unknown as PageProxy
				metas.push({ pageNumber: i, pointWidth: vp.width, pointHeight: vp.height })
			}
			pages.value = metas
			status.value = 'ready'
			// Measure after the DOM patch, not before: this function can run from a
			// pre-flush watcher callback, ahead of v-show applying the visible
			// display — measuring first would read a still-hidden container.
			await nextTick()
			measure()
			await renderCanvases(token)
		} catch {
			if (token === loadToken) { status.value = 'error' }
		}
	}

	function reload(): void {
		status.value = 'idle'
		void render()
	}

	// Invalidate any in-flight fetch/render and cancel the active draw. Call on
	// unmount so a pending async chain can't keep running — and eventually
	// write to these refs or a detached canvas — after the consumer is gone.
	function destroy(): void {
		loadToken++
		cancelActiveRender()
		currentPdf?.destroy().catch(() => {})
		currentPdf = null
	}

	return { status, pages, displayWidth, containerEl, setCanvasRef, measure, render, reload, destroy }
}
