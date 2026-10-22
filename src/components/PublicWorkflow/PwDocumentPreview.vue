<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Opt-in document preview for the anonymous public workflow. It renders the
  workflow's OWN draft PDF, fetched from the capability-gated public endpoint
  (/p/workflow/pdf, cookie-resolved on the server) — the exact source the
  field-placement editor uses. Read-only, isolated from the editor: it never
  mounts PdfEditorNext and shares no state with usePublicVisibleElements, so
  opening/closing it cannot disturb placement.

  Presentation: a bottom sheet on phones, a side panel on wider screens. The
  host keeps it mounted and toggles `open`, so the current preparation task and
  any entered data are preserved underneath while the preview is shown.
-->
<template>
	<Teleport to="body">
		<div class="pw-dp" :class="{ 'is-open': open }">
			<div class="pw-dp__scrim" @click="close" />
			<section class="pw-dp__panel"
				role="dialog"
				aria-modal="true"
				:aria-hidden="!open"
				:aria-label="t('libresign', 'Document preview')">
				<header class="pw-dp__head">
					<span class="pw-dp__grab" aria-hidden="true" />
					<span class="pw-dp__title">
						<span class="pw-dp__name">{{ name || t('libresign', 'Your document') }}</span>
						<span class="pw-dp__meta">{{ metaLine }}</span>
					</span>
					<button ref="closeBtn"
						type="button"
						class="pw-dp__close"
						:aria-label="t('libresign', 'Close preview')"
						@click="close">
						<NcIconSvgWrapper :path="mdiClose" :size="18" />
					</button>
				</header>

				<div class="pw-dp__body">
					<div v-if="status === 'loading'" class="pw-dp__state" aria-live="polite">
						<NcLoadingIcon :size="44" :name="t('libresign', 'Loading document…')" />
					</div>
					<div v-else-if="status === 'unavailable'" class="pw-dp__state">
						<NcIconSvgWrapper class="pw-dp__state-ic" :path="mdiFileDocumentOutline" :size="40" />
						<p class="pw-dp__state-title">{{ t('libresign', 'No document to preview yet') }}</p>
						<p class="pw-dp__state-sub">{{ t('libresign', 'Upload a document and it will appear here.') }}</p>
					</div>
					<div v-else-if="status === 'error'" class="pw-dp__state">
						<NcIconSvgWrapper class="pw-dp__state-ic" :path="mdiAlertCircleOutline" :size="40" />
						<p class="pw-dp__state-title">{{ t('libresign', 'Couldn’t load the preview') }}</p>
						<p class="pw-dp__state-sub">{{ t('libresign', 'Your draft is safe. You can keep preparing and try the preview again.') }}</p>
						<button type="button" class="pw-dp__retry" @click="reload">{{ t('libresign', 'Try again') }}</button>
					</div>
					<div v-show="status === 'ready'" ref="pagesEl" class="pw-dp__pages" />
				</div>
			</section>
		</div>
	</Teleport>
</template>
<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import { mdiAlertCircleOutline, mdiClose, mdiFileDocumentOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

defineOptions({ name: 'PwDocumentPreview' })

// Configure the pdf.js worker once (matched to the directly-imported main
// build) via vite's ?url loader — same pattern as helpers/pdfWorker.ts.
let workerConfigured = false
async function ensureWorker(): Promise<void> {
	if (workerConfigured) { return }
	const mod = await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
	;(pdfjsLib as unknown as { GlobalWorkerOptions: { workerSrc: string } }).GlobalWorkerOptions.workerSrc = (mod as { default: string }).default
	workerConfigured = true
}

// The capability-gated, cookie-resolved public PDF endpoint (no uuid in the URL;
// serves ONLY this workflow's own draft). Same source the editor fetches.
const PUBLIC_PDF_URL = generateUrl('/apps/libresign/p/workflow/pdf')

const props = withDefaults(defineProps<{ open: boolean, name?: string, pages?: number }>(), { name: '', pages: 0 })
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

type Status = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'
const status = ref<Status>('idle')
const pagesEl = ref<HTMLElement | null>(null)
const closeBtn = ref<HTMLElement | null>(null)

const metaLine = computed(() => {
	const parts: string[] = []
	if (props.pages && props.pages > 0) {
		parts.push(t('libresign', '{n} page(s)', { n: props.pages }))
	}
	parts.push(t('libresign', 'Draft'))
	return parts.join(' · ')
})

function close(): void {
	emit('update:open', false)
}

async function render(): Promise<void> {
	status.value = 'loading'
	try {
		await ensureWorker()
		const res = await fetch(PUBLIC_PDF_URL)
		const contentType = res.headers.get('Content-Type') ?? ''
		if (res.status === 404) { status.value = 'unavailable'; return }
		if (!res.ok || contentType.includes('application/json')) { status.value = 'error'; return }
		const data = await res.arrayBuffer()
		const pdf = await pdfjsLib.getDocument({ data }).promise
		status.value = 'ready'
		await nextTick()
		const container = pagesEl.value
		if (!container) { return }
		container.textContent = ''
		const ratio = Math.min(window.devicePixelRatio || 1, 2)
		const targetWidth = container.clientWidth || 360
		for (let i = 1; i <= pdf.numPages; i++) {
			const page = await pdf.getPage(i)
			const base = page.getViewport({ scale: 1 })
			const cssScale = targetWidth / base.width
			const viewport = page.getViewport({ scale: cssScale * ratio })
			const canvas = document.createElement('canvas')
			canvas.className = 'pw-dp__page'
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			canvas.style.width = '100%'
			canvas.style.height = 'auto'
			const ctx = canvas.getContext('2d')
			if (!ctx) { continue }
			container.appendChild(canvas)
			await page.render({ canvas, canvasContext: ctx, viewport }).promise
		}
	} catch {
		status.value = 'error'
	}
}

function reload(): void {
	status.value = 'idle'
	void render()
}

// Load on first open; cache the rendered pages for subsequent opens. A failed
// or empty attempt retries on the next open so a later upload is picked up.
watch(() => props.open, (isOpen) => {
	if (!isOpen) { return }
	if (status.value === 'idle' || status.value === 'unavailable' || status.value === 'error') {
		void render()
	}
	void nextTick(() => closeBtn.value?.focus?.())
})

function onKey(e: KeyboardEvent): void {
	if (e.key === 'Escape' && props.open) { close() }
}
if (typeof window !== 'undefined') {
	window.addEventListener('keydown', onKey)
}
</script>
<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-dp {
	@include agtokens.age-tokens;
	position: fixed;
	inset: 0;
	z-index: 10000;
	pointer-events: none;

	:deep(span.icon-vue) { min-width: 0; min-height: 0; }

	&__scrim {
		position: absolute;
		inset: 0;
		background: rgba(0, 0, 0, 0.42);
		opacity: 0;
		transition: opacity 0.24s;
	}
	&__panel {
		position: absolute;
		inset-block: 0;
		inset-inline-end: 0;
		width: min(480px, 92vw);
		background: var(--age-surface);
		border-inline-start: 1px solid var(--age-border);
		box-shadow: -24px 0 60px -30px rgba(0, 0, 0, 0.5);
		display: flex;
		flex-direction: column;
		transform: translateX(100%);
		transition: transform 0.3s cubic-bezier(0.2, 0.7, 0.3, 1);
	}

	&.is-open { pointer-events: auto; }
	&.is-open .pw-dp__scrim { opacity: 1; }
	&.is-open .pw-dp__panel { transform: none; }

	&__head {
		position: relative;
		flex: none;
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 14px 16px;
		border-bottom: 1px solid var(--age-border);
	}
	&__grab { display: none; }
	&__title { min-width: 0; flex: 1 1 auto; display: flex; flex-direction: column; }
	&__name { font-size: 0.95rem; font-weight: 600; color: var(--age-fg); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__meta { font-size: 0.78rem; color: var(--age-fg-muted); }
	&__close {
		flex: none;
		width: 40px;
		height: 40px;
		border: 1px solid var(--age-border);
		border-radius: 50%;
		background: var(--age-surface);
		color: var(--age-fg-muted);
		display: grid;
		place-items: center;
		cursor: pointer;
		transition: color 0.16s, border-color 0.16s;
		&:hover { color: var(--age-fg); border-color: var(--age-border-strong); }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 2px; }
	}

	&__body { flex: 1 1 auto; min-height: 0; overflow: auto; background: var(--age-surface-2); }
	&__pages { display: flex; flex-direction: column; gap: 14px; padding: 18px; }
	:deep(.pw-dp__page) {
		width: 100%;
		height: auto;
		border-radius: 6px;
		box-shadow: 0 2px 6px rgba(13, 17, 22, 0.06), 0 20px 40px -22px rgba(13, 17, 22, 0.34);
		background: #fff;
	}

	&__state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 100%;
		padding: 40px 24px;
		text-align: center;
	}
	&__state-ic { color: var(--age-fg-faint); }
	&__state-title { margin: 0; font-size: 1rem; font-weight: 600; color: var(--age-fg); }
	&__state-sub { margin: 0; font-size: 0.84rem; color: var(--age-fg-muted); max-width: 40ch; }
	&__retry {
		margin-top: 4px;
		border: 1px solid var(--age-border-strong);
		background: var(--age-surface);
		color: var(--age-fg);
		font: inherit;
		font-size: 0.86rem;
		font-weight: 600;
		padding: 9px 16px;
		border-radius: var(--age-radius-sm);
		cursor: pointer;
		&:hover { background: var(--age-surface-2); }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 2px; }
	}
}

// Bottom sheet on phones.
@media (max-width: 767px) {
	.pw-dp__panel {
		inset-block: auto 0;
		inset-inline: 0;
		width: 100%;
		height: 86vh;
		border-inline-start: none;
		border-top: 1px solid var(--age-border);
		border-radius: 18px 18px 0 0;
		transform: translateY(100%);
		box-shadow: 0 -24px 60px -30px rgba(0, 0, 0, 0.5);
	}
	.pw-dp.is-open .pw-dp__panel { transform: none; }
	.pw-dp__grab {
		display: block;
		position: absolute;
		top: 7px;
		inset-inline-start: 50%;
		transform: translateX(-50%);
		width: 40px;
		height: 4px;
		border-radius: 999px;
		background: var(--age-border-strong);
	}
	.pw-dp__head { padding-top: 18px; }
}

@media (prefers-reduced-motion: reduce) {
	.pw-dp__scrim, .pw-dp__panel { transition: none; }
}
</style>
