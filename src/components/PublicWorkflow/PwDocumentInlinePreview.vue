<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Inline PDF preview for the public workflow stage.
  Fetching starts on first activation; keep the component mounted with v-show
  so switching between Summary and Preview preserves the rendered pages.

  Projects placed signature positions back onto the rendered pages as inert,
  signer-coloured boxes — the same read-only overlay PwDocumentStage.vue
  draws for PublicWorkflowNext, ported here so both document panes agree on
  what the signer is about to sign. The element source is the document prop
  (store-reactive), so a save in the editor updates the overlay with no PDF
  re-fetch.
-->
<template>
    <div ref="containerEl" class="pdf-page-preview">
        <div v-if="status === 'loading'" class="pdf-page-preview__state" aria-live="polite">
            <NcLoadingIcon :size="36" :name="t('libresign', 'Loading document…')" />
        </div>
        <div v-else-if="status === 'unavailable'" class="pdf-page-preview__state">
            <NcIconSvgWrapper class="pdf-page-preview__state-ic" :path="mdiFileDocumentOutline" :size="34" />
            <p class="pdf-page-preview__state-title">{{ t('libresign', 'No document to preview yet') }}</p>
            <p class="pdf-page-preview__state-sub">{{ t('libresign', 'Upload a document and it will appear here.') }}</p>
        </div>
        <div v-else-if="status === 'error'" class="pdf-page-preview__state">
            <NcIconSvgWrapper class="pdf-page-preview__state-ic" :path="mdiAlertCircleOutline" :size="34" />
            <p class="pdf-page-preview__state-title">{{ t('libresign', 'Couldn’t load the preview') }}</p>
            <p class="pdf-page-preview__state-sub">{{ t('libresign', 'Your draft is safe. You can keep preparing and try the preview again.') }}</p>
            <button type="button" class="pdf-page-preview__retry" @click="reload">{{ t('libresign', 'Try again') }}</button>
        </div>
        <div v-show="status === 'ready'" class="pdf-page-preview__pages">
            <div v-for="pg in pages"
                :key="pg.pageNumber"
                class="pdf-page-preview__page-wrap"
                :style="{ width: displayWidth + 'px' }">
                <canvas :ref="(el: Element | null) => setCanvasRef(pg.pageNumber, el)" class="pdf-page-preview__page" />
                <div class="pdf-page-preview__overlay" aria-hidden="true">
                    <div v-for="box in boxesFor(pg.pageNumber)"
                        :key="box.key"
                        class="pdf-page-preview__field"
                        :style="box.style">
                        <span class="pdf-page-preview__field-ic" :style="{ color: box.color }">
                            <NcIconSvgWrapper :path="mdiDrawPen" :size="12" />
                        </span>
                        <span class="pdf-page-preview__field-label">{{ t('libresign', 'Sign') }}</span>
                        <span v-if="box.who" class="pdf-page-preview__field-who">· {{ box.who }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mdiAlertCircleOutline, mdiDrawPen, mdiFileDocumentOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { usePdfPagePreview } from '../../composables/usePdfPagePreview'
import { useSignerColors } from '../Request/VisibleElementsNext/composables/useSignerColors'
import { normaliseVisibleElement } from '../Request/VisibleElementsNext/shared/visibleElementsShared'
import { getVisibleElementsFromDocument, type DocumentLike } from '../../services/visibleElementsService'
import type { WorkflowDocument, WorkflowSigner } from '../../services/publicWorkflow'

defineOptions({ name: 'PwDocumentInlinePreview' })

const PUBLIC_PDF_URL = generateUrl('/apps/libresign/p/workflow/pdf')

const props = defineProps<{
    active: boolean
    document: WorkflowDocument | null
}>()

const { status, pages, displayWidth, containerEl, setCanvasRef, measure, render, reload, destroy } = usePdfPagePreview(PUBLIC_PDF_URL)

// Load on first activation and reuse rendered pages on subsequent visits.
// Retry when loading fails or no document is available.
watch(() => props.active, (isActive) => {
    if (!isActive) { return }
    if (status.value === 'idle' || status.value === 'unavailable' || status.value === 'error') {
        void render()
    }
}, { immediate: true })

let resizeObserver: ResizeObserver | null = null
onMounted(() => {
    if (typeof ResizeObserver !== 'undefined' && containerEl.value) {
        resizeObserver = new ResizeObserver(() => measure())
        resizeObserver.observe(containerEl.value)
    }
})
onBeforeUnmount(() => {
    resizeObserver?.disconnect()
    resizeObserver = null
    // Cancel any in-flight fetch/render so it can't keep running — and
    // eventually touch a detached canvas or an orphaned ref — once this
    // component is gone (e.g. navigating away mid-load).
    destroy()
})

const { colorFor, registerKeys, reset: resetColors } = useSignerColors()

// Signer presentation: id -> record, registered in document order so colours
// are stable and match the editor's first-seen assignment.
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
// their document-LOCAL page. Reactive on the document prop — a save re-groups
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
</script>
<style scoped lang="scss">
.pdf-page-preview {
    &__pages { display: flex; flex-direction: column; align-items: center; gap: 14px; }
    &__page-wrap { position: relative; border-radius: 6px; overflow: hidden; }
    &__page {
        display: block;
        width: 100%;
        height: auto;
        border-radius: 6px;
        box-shadow: 0 2px 6px rgba(13, 17, 22, 0.06), 0 20px 40px -22px rgba(13, 17, 22, 0.34);
        background: #fff;
    }
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
    &__state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 48px 24px; text-align: center; }
    &__state-ic { color: var(--age-fg-faint, var(--color-text-maxcontrast)); }
    &__state-title { margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--age-fg, var(--color-main-text)); }
    &__state-sub { margin: 0; font-size: 0.82rem; color: var(--age-fg-muted, var(--color-text-maxcontrast)); max-width: 40ch; }
    &__retry { margin-top: 4px; border: 1px solid var(--age-border-strong, var(--color-border-dark)); background: var(--age-surface, var(--color-main-background)); color: var(--age-fg, var(--color-main-text)); font: inherit; font-size: 0.84rem; font-weight: 600; padding: 9px 16px; border-radius: var(--age-radius-sm, 8px); cursor: pointer; &:hover { background: var(--age-surface-2, var(--color-background-hover)); } &:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: 2px; } }
}
</style>
