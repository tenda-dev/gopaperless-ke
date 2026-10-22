<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Persistent, READ-ONLY document stage for the redesigned public workflow
  (PublicWorkflowNext). It renders the workflow's OWN draft PDF from the
  capability-gated public endpoint (/p/workflow/pdf, cookie-resolved server
  side — the exact source the field editor uses) and projects the placed
  signature positions back onto the rendered pages as inert, signer-coloured
  boxes.

  Isolation: it NEVER mounts PdfEditorNext and shares no state with
  usePublicVisibleElements. It consumes the shared serialisation engine
  (getVisibleElementsFromDocument + normaliseVisibleElement) and signer colours
  (useSignerColors) read-only, so it cannot disturb placement. Boxes are
  pointer-events:none; the overlay is purely presentational. The element source
  is reactive (store document), so a save in the editor updates the overlay with
  no PDF re-fetch.

  Coordinate contract (verified): stored coordinates are PDF points, top-left
  origin — x=left, y=top, width, height — on a document-LOCAL page number. Each
  page's point dimensions come from pdf.js getViewport({scale:1}); the overlay
  scale is cssDisplayedWidth / pagePointWidth.
-->
<template>
	<section class="pw-stage" :class="{ 'is-empty': status !== 'ready' }">
		<div class="pw-stage__bar">
			<div ref="metaWrap" class="pw-stage__doc-wrap">
				<button type="button"
					class="pw-stage__doc"
					:class="{ 'is-open': metaOpen }"
					:disabled="status !== 'ready'"
					:aria-expanded="metaOpen"
					@click="metaOpen = !metaOpen">
					<NcIconSvgWrapper class="pw-stage__doc-ic" :path="mdiFileDocumentOutline" :size="15" />
					<span class="pw-stage__doc-name">{{ docName }}</span>
					<NcIconSvgWrapper class="pw-stage__doc-chev" :class="{ 'is-open': metaOpen }" :path="mdiChevronDown" :size="15" />
				</button>
				<transition name="pw-stage-meta">
					<div v-if="metaOpen" class="pw-stage__meta" role="region" :aria-label="t('libresign', 'Document details')">
						<dl class="pw-stage__meta-list">
							<div class="pw-stage__meta-row">
								<dt>{{ t('libresign', 'Pages') }}</dt>
								<dd>{{ props.pages > 0 ? props.pages : '—' }}</dd>
							</div>
							<div class="pw-stage__meta-row">
								<dt>{{ t('libresign', 'Status') }}</dt>
								<dd><span class="pw-stage__badge"><NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="12" /> {{ t('libresign', 'Draft') }}</span></dd>
							</div>
							<div v-if="props.expiry" class="pw-stage__meta-row">
								<dt>{{ t('libresign', 'Expires') }}</dt>
								<dd>{{ props.expiry }}</dd>
							</div>
							<div class="pw-stage__meta-row">
								<dt>{{ t('libresign', 'Privacy') }}</dt>
								<dd>{{ t('libresign', 'Kept private · nothing sent') }}</dd>
							</div>
						</dl>
					</div>
				</transition>
			</div>
		</div>

		<div ref="scrollEl" class="pw-stage__scroll">
			<div v-if="status === 'loading'" class="pw-stage__state" aria-live="polite">
				<NcLoadingIcon :size="40" :name="t('libresign', 'Loading document…')" />
			</div>
			<div v-else-if="status === 'unavailable'" class="pw-stage__state">
				<NcIconSvgWrapper class="pw-stage__state-ic" :path="mdiFileDocumentOutline" :size="40" />
				<p class="pw-stage__state-title">{{ t('libresign', 'Your document will appear here') }}</p>
				<p class="pw-stage__state-sub">{{ t('libresign', 'Add a document and it stays in view through every step.') }}</p>
			</div>
			<div v-else-if="status === 'error'" class="pw-stage__state">
				<NcIconSvgWrapper class="pw-stage__state-ic" :path="mdiAlertCircleOutline" :size="40" />
				<p class="pw-stage__state-title">{{ t('libresign', 'Couldn’t load the document') }}</p>
				<p class="pw-stage__state-sub">{{ t('libresign', 'Your draft is safe. You can keep preparing and try again.') }}</p>
				<button type="button" class="pw-stage__retry" @click="reload">{{ t('libresign', 'Try again') }}</button>
			</div>

			<div v-show="status === 'ready'" class="pw-stage__pages">
				<div v-for="pg in pages"
					:key="pg.pageNumber"
					class="pw-stage__page"
					:style="{ width: displayWidth + 'px' }">
					<canvas :ref="(el: Element | null) => setCanvasRef(pg.pageNumber, el)" class="pw-stage__canvas" />
					<div class="pw-stage__overlay" aria-hidden="true">
						<div v-for="box in boxesFor(pg.pageNumber)"
							:key="box.key"
							class="pw-stage__field"
							:style="box.style">
							<span class="pw-stage__field-ic" :style="{ color: box.color }">
								<NcIconSvgWrapper :path="mdiDrawPen" :size="12" />
							</span>
							<span class="pw-stage__field-label">{{ t('libresign', 'Sign') }}</span>
							<span v-if="box.who" class="pw-stage__field-who">· {{ box.who }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<p v-if="status === 'ready'" class="pw-stage__hint">
			{{ hasFields
				? t('libresign', 'Fields shown in place — nothing is sent while you prepare.')
				: t('libresign', 'Your document stays in view through every step.') }}
		</p>
	</section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import { mdiAlertCircleOutline, mdiChevronDown, mdiDrawPen, mdiFileDocumentOutline, mdiShieldCheckOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { useSignerColors } from '../Request/VisibleElementsNext/composables/useSignerColors'
import { normaliseVisibleElement } from '../Request/VisibleElementsNext/shared/visibleElementsShared'
import { getVisibleElementsFromDocument, type DocumentLike } from '../../services/visibleElementsService'
import type { WorkflowDocument, WorkflowSigner } from '../../services/publicWorkflow'

defineOptions({ name: 'PwDocumentStage' })

const props = withDefaults(defineProps<{
	document: WorkflowDocument | null
	active?: boolean
	pages?: number
	expiry?: string
}>(), { active: true, pages: 0, expiry: '' })

// pdf.js worker — configured once, matched to the directly-imported main build
// (same pattern as PwDocumentPreview / helpers/pdfWorker).
let workerConfigured = false
async function ensureWorker(): Promise<void> {
	if (workerConfigured) { return }
	const mod = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')
	;(pdfjsLib as unknown as { GlobalWorkerOptions: { workerSrc: string } }).GlobalWorkerOptions.workerSrc = (mod as { default: string }).default
	workerConfigured = true
}

// Cookie-resolved public PDF endpoint (no uuid in the URL — serves ONLY this
// workflow's own draft). The generic /p/pdf/{uuid} route 403s anonymously.
const PUBLIC_PDF_URL = generateUrl('/apps/libresign/p/workflow/pdf')

// Render each page's bitmap at a generous fixed CSS width so CSS downscaling to
// the actual column width stays crisp without re-rendering pdf.js on resize.
const RENDER_CSS_WIDTH = 680
const MAX_DISPLAY_WIDTH = 560

type Status = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'
type PageInfo = { pageNumber: number, pointWidth: number, pointHeight: number }

const status = ref<Status>('idle')
const pages = ref<PageInfo[]>([])
const displayWidth = ref<number>(480)
const scrollEl = ref<HTMLElement | null>(null)

const canvasRefs = new Map<number, HTMLCanvasElement>()
let pageProxies: Array<{ getViewport: (o: { scale: number }) => { width: number, height: number }, render: (p: Record<string, unknown>) => { promise: Promise<void> } }> = []
let loadToken = 0
let resizeObserver: ResizeObserver | null = null

const { colorFor, registerKeys, reset: resetColors } = useSignerColors()

const docName = computed(() => props.document?.name || t('libresign', 'Your document'))

// Centred document-title expansion panel (file metadata). Closes on outside
// click and Escape so it never traps focus over the document.
const metaOpen = ref(false)
const metaWrap = ref<HTMLElement | null>(null)
function onDocPointerDown(e: Event): void {
	if (!metaOpen.value) { return }
	const target = e.target as Node | null
	if (metaWrap.value && target && !metaWrap.value.contains(target)) { metaOpen.value = false }
}
function onDocKeydown(e: KeyboardEvent): void {
	if (e.key === 'Escape' && metaOpen.value) { metaOpen.value = false }
}

// Signer presentation: id -> name, registered in document order so colours are
// stable and match the editor's first-seen assignment.
const signerById = computed<Map<number, WorkflowSigner>>(() => {
	const map = new Map<number, WorkflowSigner>()
	const list = Array.isArray(props.document?.signers) ? props.document!.signers! : []
	list.forEach((signer) => {
		if (typeof signer.signRequestId === 'number') { map.set(signer.signRequestId, signer) }
	})
	return map
})
watch(signerById, (map) => {
	resetColors()
	registerKeys(Array.from(map.keys()))
}, { immediate: true })

function signerName(signer: WorkflowSigner | undefined): string {
	if (!signer) { return '' }
	if (signer.displayName) { return signer.displayName }
	if (signer.email) { return signer.email }
	const email = signer.identifyMethods?.find((m) => m.method === 'email')?.value
	return email ?? ''
}

// Normalised placed elements (same source the editor seeds from), grouped by
// their document-LOCAL page. Reactive on the store document — a save re-groups
// with no PDF re-fetch.
const elementsByPage = computed<Map<number, Array<{ key: string, page: number, left: number, top: number, width: number, height: number, signRequestId: number }>>>(() => {
	const grouped = new Map<number, Array<{ key: string, page: number, left: number, top: number, width: number, height: number, signRequestId: number }>>()
	const doc = props.document
	if (!doc) { return grouped }
	const raw = getVisibleElementsFromDocument(doc as unknown as DocumentLike)
	raw.forEach((element) => {
		const n = normaliseVisibleElement(element)
		if (!n) { return }
		const w = n.coordinates.width
		const h = n.coordinates.height
		if (w === undefined || h === undefined) { return }
		const page = Number(n.coordinates.page)
		const row = grouped.get(page) ?? []
		row.push({
			key: `${n.elementId}`,
			page,
			left: Number(n.coordinates.left),
			top: Number(n.coordinates.top),
			width: Number(w),
			height: Number(h),
			signRequestId: n.signRequestId,
		})
		grouped.set(page, row)
	})
	return grouped
})

const hasFields = computed(() => elementsByPage.value.size > 0)

// Build the absolutely-positioned, inert box styles for one rendered page.
// cssScale maps PDF points -> displayed CSS px (displayed page width == displayWidth).
function boxesFor(pageNumber: number): Array<{ key: string, who: string, color: string, style: Record<string, string> }> {
	const info = pages.value.find((p) => p.pageNumber === pageNumber)
	const rows = elementsByPage.value.get(pageNumber)
	if (!info || !rows || info.pointWidth <= 0) { return [] }
	const cssScale = displayWidth.value / info.pointWidth
	return rows.map((box) => {
		const palette = colorFor(box.signRequestId)
		return {
			key: box.key,
			who: signerName(signerById.value.get(box.signRequestId)),
			color: palette.base,
			style: {
				left: `${box.left * cssScale}px`,
				top: `${box.top * cssScale}px`,
				width: `${box.width * cssScale}px`,
				height: `${box.height * cssScale}px`,
				'--pw-field-border': palette.base,
				'--pw-field-tint': palette.tint,
				'--pw-field-text': palette.base,
			},
		}
	})
}

function setCanvasRef(pageNumber: number, el: Element | null): void {
	if (el instanceof HTMLCanvasElement) {
		canvasRefs.set(pageNumber, el)
	} else {
		canvasRefs.delete(pageNumber)
	}
}

function measure(): void {
	const el = scrollEl.value
	if (!el) { return }
	const available = el.clientWidth - 56 // horizontal padding of the pages column
	displayWidth.value = Math.max(220, Math.min(MAX_DISPLAY_WIDTH, available))
}

async function renderCanvases(token: number): Promise<void> {
	const ratio = Math.min(window.devicePixelRatio || 1, 2)
	for (const info of pages.value) {
		if (token !== loadToken) { return }
		const canvas = canvasRefs.get(info.pageNumber)
		const proxy = pageProxies[info.pageNumber - 1]
		if (!canvas || !proxy) { continue }
		const scale = (RENDER_CSS_WIDTH / info.pointWidth) * ratio
		const viewport = proxy.getViewport({ scale })
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvas.style.width = '100%'
		canvas.style.height = 'auto'
		const ctx = canvas.getContext('2d')
		if (!ctx) { continue }
		await proxy.render({ canvas, canvasContext: ctx, viewport }).promise
	}
}

async function render(): Promise<void> {
	const token = ++loadToken
	status.value = 'loading'
	pages.value = []
	canvasRefs.clear()
	pageProxies = []
	try {
		await ensureWorker()
		const res = await fetch(PUBLIC_PDF_URL)
		const contentType = res.headers.get('Content-Type') ?? ''
		if (res.status === 404) { if (token === loadToken) { status.value = 'unavailable' } return }
		if (!res.ok || contentType.includes('application/json')) { if (token === loadToken) { status.value = 'error' } return }
		const data = await res.arrayBuffer()
		if (token !== loadToken) { return }
		const pdf = await pdfjsLib.getDocument({ data }).promise
		const metas: PageInfo[] = []
		for (let i = 1; i <= pdf.numPages; i++) {
			const page = await pdf.getPage(i)
			if (token !== loadToken) { return }
			const vp = page.getViewport({ scale: 1 })
			pageProxies[i - 1] = page as unknown as typeof pageProxies[number]
			metas.push({ pageNumber: i, pointWidth: vp.width, pointHeight: vp.height })
		}
		pages.value = metas
		status.value = 'ready'
		measure()
		await nextTick()
		await renderCanvases(token)
	} catch {
		if (token === loadToken) { status.value = 'error' }
	}
}

function reload(): void {
	status.value = 'idle'
	void render()
}

// (Re)load when the stage becomes active with a document, and whenever the
// document identity changes (first upload, or a swapped draft). The overlay
// itself reacts to visibleElements without a re-fetch.
const docKey = computed(() => (props.document ? `${props.document.uuid ?? ''}:${props.document.name ?? ''}` : ''))
watch([() => props.active, docKey], ([active, key], [prevActive, prevKey] = [undefined, undefined]) => {
	if (!active) { return }
	if (!props.document || key === '') {
		status.value = 'unavailable'
		return
	}
	if (status.value === 'idle' || status.value === 'unavailable' || status.value === 'error' || key !== prevKey) {
		void render()
	}
}, { immediate: true })

onMounted(() => {
	if (typeof ResizeObserver !== 'undefined' && scrollEl.value) {
		resizeObserver = new ResizeObserver(() => measure())
		resizeObserver.observe(scrollEl.value)
	}
	measure()
	document.addEventListener('pointerdown', onDocPointerDown, true)
	window.addEventListener('keydown', onDocKeydown)
})
onBeforeUnmount(() => {
	loadToken++
	resizeObserver?.disconnect()
	resizeObserver = null
	document.removeEventListener('pointerdown', onDocPointerDown, true)
	window.removeEventListener('keydown', onDocKeydown)
})

defineExpose({ reload })
</script>

<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-stage {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	min-height: 0;
	height: 100%;
	background: radial-gradient(120% 90% at 50% 0%, var(--age-surface-2, var(--color-background-dark)), var(--age-surface-hover, var(--color-background-hover)) 60%);

	:deep(span.icon-vue) { min-width: 0; min-height: 0; }

	// Centred document-title bar with a click-to-expand metadata panel.
	&__bar {
		flex: none;
		display: flex;
		justify-content: center;
		padding: 14px 24px 8px;
	}
	&__doc-wrap { position: relative; display: flex; justify-content: center; }
	&__doc {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		max-width: min(70vw, 420px);
		border: 1px solid var(--age-border, var(--color-border));
		background: var(--age-surface, var(--color-main-background));
		color: var(--age-fg, var(--color-main-text));
		border-radius: 999px;
		padding: 6px 12px 6px 13px;
		font: inherit;
		font-size: 12.5px;
		font-weight: 600;
		line-height: 1.2;
		cursor: pointer;
		box-shadow: 0 1px 2px rgba(13, 17, 22, 0.05);
		transition: border-color 0.16s, background 0.16s, box-shadow 0.16s;
		&:hover:not(:disabled) { border-color: var(--age-border-strong, var(--color-border-dark)); }
		&.is-open { border-color: var(--age-success, var(--color-primary-element)); box-shadow: 0 0 0 3px var(--age-success-tint, rgba(4, 213, 109, 0.12)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: 2px; }
		&:disabled { opacity: 0.6; cursor: default; }
	}
	&__doc-ic { flex: none; color: var(--age-success-fg, #0a6636); }
	&__doc-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__doc-chev { flex: none; color: var(--age-fg-faint, var(--color-text-maxcontrast)); transition: transform 0.18s; &.is-open { transform: rotate(180deg); } }

	&__meta {
		position: absolute;
		top: calc(100% + 8px);
		inset-inline-start: 50%;
		transform: translateX(-50%);
		z-index: 20;
		width: max-content;
		max-width: min(86vw, 320px);
		background: var(--age-surface, var(--color-main-background));
		border: 1px solid var(--age-border, var(--color-border));
		border-radius: var(--age-radius, 12px);
		box-shadow: 0 2px 6px rgba(13, 17, 22, 0.06), 0 24px 48px -24px rgba(13, 17, 22, 0.4);
		padding: 10px 14px;
	}
	&__meta-list { margin: 0; display: flex; flex-direction: column; }
	&__meta-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 18px;
		padding: 7px 0;
		font-size: 12.5px;
		& + & { border-top: 1px solid var(--age-border, var(--color-border)); }
		dt { color: var(--age-fg-muted, var(--color-text-maxcontrast)); margin: 0; }
		dd { margin: 0; color: var(--age-fg, var(--color-main-text)); font-weight: 600; text-align: end; }
	}
	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		font-family: var(--tenda-font-mono, var(--mono, monospace));
		font-size: 10px;
		font-weight: 600;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--age-success-fg, #0a6636);
		padding: 3px 8px;
		border-radius: 999px;
		background: var(--age-success-tint, rgba(4, 213, 109, 0.12));
		border: 1px solid var(--age-success-border, rgba(4, 213, 109, 0.34));
		:deep(span.icon-vue) { color: var(--age-success-fg, #0a6636); }
	}

	&__scroll {
		flex: 1 1 auto;
		min-height: 0;
		overflow: auto;
		display: grid;
		place-items: start center;
		padding: 8px 28px 40px;
	}
	&__pages { display: flex; flex-direction: column; gap: 16px; width: 100%; align-items: center; }
	&__page {
		position: relative;
		border-radius: 6px;
		box-shadow: 0 2px 6px rgba(13, 17, 22, 0.06), 0 30px 60px -24px rgba(13, 17, 22, 0.35);
		background: #fff;
		overflow: hidden;
	}
	&__canvas { display: block; width: 100%; height: auto; }
	&__overlay { position: absolute; inset: 0; pointer-events: none; }

	&__field {
		position: absolute;
		display: flex;
		align-items: center;
		gap: 5px;
		padding: 4px 7px;
		border-radius: 6px;
		border: 1.5px dashed var(--pw-field-border, var(--age-success-fg, #03b95e));
		background: var(--pw-field-tint, rgba(4, 213, 109, 0.12));
		color: var(--pw-field-text, var(--age-success-fg, #0a6636));
		font-size: 10px;
		font-weight: 600;
		overflow: hidden;
		white-space: nowrap;
		box-shadow: 0 6px 16px -8px rgba(13, 17, 22, 0.4);
	}
	&__field-ic { flex: none; display: inline-flex; }
	&__field-label { flex: none; }
	&__field-who {
		font-family: var(--tenda-font-mono, var(--mono, monospace));
		font-weight: 500;
		font-size: 9px;
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 100%;
		min-height: 220px;
		padding: 40px 24px;
		text-align: center;
	}
	&__state-ic { color: var(--age-fg-faint, var(--color-text-maxcontrast)); }
	&__state-title { margin: 0; font-size: 1rem; font-weight: 600; color: var(--age-fg, var(--color-main-text)); }
	&__state-sub { margin: 0; font-size: 0.84rem; color: var(--age-fg-muted, var(--color-text-maxcontrast)); max-width: 40ch; }
	&__retry {
		margin-top: 4px;
		border: 1px solid var(--age-border-strong, var(--color-border-dark));
		background: var(--age-surface, var(--color-main-background));
		color: var(--age-fg, var(--color-main-text));
		font: inherit;
		font-size: 0.86rem;
		font-weight: 600;
		padding: 9px 16px;
		border-radius: var(--age-radius-sm, 9px);
		cursor: pointer;
		&:hover { background: var(--age-surface-2, var(--color-background-hover)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: 2px; }
	}

	&__hint {
		flex: none;
		text-align: center;
		font-size: 11.5px;
		color: var(--age-fg-faint, var(--color-text-maxcontrast));
		margin: 0;
		padding: 0 24px 14px;
	}
}

.pw-stage-meta-enter-active,
.pw-stage-meta-leave-active { transition: opacity 0.16s ease, transform 0.16s ease; }

.pw-stage-meta-enter-from,
.pw-stage-meta-leave-to { opacity: 0; transform: translateX(-50%) translateY(-4px); }

@media (prefers-reduced-motion: reduce) {
	.pw-stage-meta-enter-active,
	.pw-stage-meta-leave-active { transition: none; }
}
</style>
