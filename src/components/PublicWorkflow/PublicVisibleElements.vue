<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  PublicVisibleElements — the anonymous workflow's signature-positions editor.
  A dedicated public integration: it reuses PdfEditorNext and the prop-driven
  Ve* components, but all state/persistence flows through usePublicVisibleElements
  (public store + public save/delete). No authenticated store or endpoints.
  Scoped to a single document.
-->
<template>
	<NcModal v-if="modal"
		:name="t('libresign', 'Signature positions')"
		:close-button-contained="false"
		:close-button-outside="true"
		class="pve-editor-modal"
		size="full"
		@close="closeModal">
		<div v-if="loading" class="pve__loading">
			<NcLoadingIcon :size="64" :name="t('libresign', 'Loading …')" />
		</div>
		<div v-else class="pve" :class="{ 'pve--mobile': isMobile }">
			<aside v-if="!isMobile" class="pve__sidebar">
				<h2 class="pve__name">{{ doc.name }}</h2>
				<div v-if="pdfReady" class="pve__count">
					<span class="pve__count-label">{{ t('libresign', 'Signers') }}</span>
					<VeCompletenessPill :placed="placedCount" :total="signerCount" />
				</div>

				<div class="pve__signers">
					<VeSignerSelect v-for="signer in signerList"
						:key="signer.signRequestId"
						:signer="signer"
						:palette="colorFor(signer.signRequestId)"
						:placed-count="countBySigner[signer.signRequestId] || 0"
						:reviewing="focusedSignerId === signer.signRequestId"
						:disabled="mode === 'place' && !isSelectedSigner(signer)"
						:overlapping="overlappingSignerIds.has(signer.signRequestId)"
						layout="row"
						@select="onSelectSigner"
						@review="onReviewSigner" />
				</div>

				<NcNoteCard v-if="pdfReady && overlapText" type="error" :text="overlapText" />
				<NcNoteCard v-else-if="pdfReady && partialEmpty" type="warning" :text="emptyWarning" />

				<VeButton v-if="canSave"
					:variant="anyEmpty ? 'secondary' : 'primary'"
					wide
					:disabled="!pdfReady || loading || mode === 'place'"
					@click="save()">
					{{ anyEmpty ? t('libresign', 'Save anyway') : t('libresign', 'Save positions') }}
				</VeButton>
			</aside>

			<div class="pve__center">
				<VePlacementBanner v-if="!isMobile && pdfReady && mode !== 'browse'"
					class="pve__banner"
					:mode="mode"
					:active-signer-name="activeSignerName"
					:show-edit-toggle="false"
					@cancel="stopAddSigner"
					@show-all="onShowAll"
					@toggle-edit="toggleEdit" />

				<div class="pve__canvas">
					<div v-if="!pdfReady" class="pve__overlay">
						<NcLoadingIcon :size="64" :name="t('libresign', 'Preparing document for signature placement…')" />
					</div>
					<PdfEditor v-if="pdfEditorFiles.length > 0"
						ref="pdfEditor"
						width="100%"
						height="100%"
						:files="pdfEditorFiles"
						:file-names="pdfFileNames"
						:signers="pdfEditorSigners"
						:read-only="pdfReadOnly"
						:active-sign-request-id="activeSignRequestId"
						:signer-colors="colorMap"
						:overlapping-ids="overlappingIds"
						:highlight-id="highlightId"
						@pdf-editor:end-init="handlePdfReady"
						@pdf-editor:object-click="handleObjectClick"
						@pdf-editor:adding-ended="handleAddingEnded"
						@pdf-editor:on-delete-signer="handleDeleteSigner" />
				</div>
			</div>

			<VePageRail v-if="pdfReady && railPages.length > 1 && !isMobile"
				class="pve__rail"
				:pages="railPages"
				:signers-by-page="signersByPage"
				:current-global-page="currentGlobalPage"
				:color-for="railColorFor"
				@select-page="onSelectPage" />

			<VeSignerSheet v-if="isMobile && pdfReady"
				:document-name="doc.name || ''"
				:signers="signerList"
				:color-for="colorFor"
				:count-by-signer="countBySigner"
				:placed-count="placedCount"
				:signer-count="signerCount"
				:any-empty="anyEmpty"
				:overlap-text="overlapText"
				:overlapping-signers="overlappingSignerIds"
				:reviewing-signer-id="focusedSignerId"
				:can-save="canSave && !loading"
				:adjusting="mode === 'edit'"
				@select="onSelectSigner"
				@review="onReviewSigner"
				@save="save()"
				@done="onSheetDone" />
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import PdfEditor from '../Request/VisibleElementsNext/PdfEditorNext.vue'
import VeButton from '../Request/VisibleElementsNext/VeButton.vue'
import VeCompletenessPill from '../Request/VisibleElementsNext/VeCompletenessPill.vue'
import VePageRail from '../Request/VisibleElementsNext/VePageRail.vue'
import VePlacementBanner from '../Request/VisibleElementsNext/VePlacementBanner.vue'
import VeSignerSelect from '../Request/VisibleElementsNext/VeSignerSelect.vue'
import VeSignerSheet from '../Request/VisibleElementsNext/VeSignerSheet.vue'

import { usePublicVisibleElements } from './composables/usePublicVisibleElements'

defineOptions({ name: 'PublicVisibleElements' })

const {
	pdfEditor,
	modal,
	loading,
	pdfReady,
	mode,
	focusedSignerId,
	isMobile,
	document: doc,
	pdfEditorFiles,
	pdfFileNames,
	pdfReadOnly,
	pdfEditorSigners,
	colorMap,
	overlappingIds,
	highlightId,
	signerList,
	signerCount,
	placedCount,
	countBySigner,
	signersByPage,
	railPages,
	currentGlobalPage,
	railColorFor,
	onSelectPage,
	activeSignRequestId,
	activeSignerName,
	anyEmpty,
	partialEmpty,
	emptyWarning,
	overlapText,
	overlappingSignerIds,
	canSave,
	colorFor,
	isSelectedSigner,
	open,
	closeModal,
	handlePdfReady,
	handleObjectClick,
	handleAddingEnded,
	handleDeleteSigner,
	onSelectSigner,
	onReviewSigner,
	onShowAll,
	onSheetDone,
	stopAddSigner,
	toggleEdit,
	save,
	setMobile,
	t,
} = usePublicVisibleElements()

let mq: MediaQueryList | undefined
function syncMobile() {
	setMobile(!!mq?.matches)
}

onMounted(() => {
	mq = window.matchMedia('(max-width: 767px)')
	syncMobile()
	mq.addEventListener?.('change', syncMobile)
})
onBeforeUnmount(() => {
	mq?.removeEventListener?.('change', syncMobile)
})

// Opened by the host (the Concept B shell, Slice 3) via the exposed open().
defineExpose({ open, closeModal, modal })
</script>

<style scoped lang="scss">
.pve {
	display: flex;
	width: 100%;
	height: 100%;
	min-height: 0;
	position: relative;

	&--mobile {
		flex-direction: column;

		// Reserve room so the LAST page scrolls clear of the mobile peek sheet
		// (~208px) instead of being trapped behind it — parity with the authenticated editor.
		:deep(.pages-container) {
			padding-bottom: calc(208px + 16px + env(safe-area-inset-bottom, 0px));
		}
	}

	&__loading {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 100%;
	}

	&__sidebar {
		width: 340px;
		min-width: 340px;
		flex: none;
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 12px;
		border-inline-end: 1px solid var(--color-border);
		overflow-y: auto;
	}

	&__name {
		font-size: 16px;
		font-weight: 600;
		margin: 0;
		overflow-wrap: anywhere;
		color: var(--color-main-text);
	}

	// Mirrors the authenticated editor's "Signers" header above the pill.
	&__count {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
	}

	&__count-label {
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__signers {
		display: flex;
		flex-direction: column;
		gap: 6px;
		overflow-y: auto;
	}

	&__center {
		position: relative;
		flex: 1;
		min-height: 0;
		display: flex;
		flex-direction: column;
	}

	// Normal flow (8px margin), like the authenticated editor — NOT absolute, so
	// it sits above the canvas and pushes it down instead of floating over the PDF.
	&__banner {
		margin: 8px;
	}

	&__canvas {
		position: relative;
		flex: 1;
		min-height: 0;
	}

	&__overlay {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 1;
	}

	// Page rail (desktop only), matching the authenticated editor's right column.
	&__rail {
		flex-shrink: 0;
		border-inline-start: 1px solid var(--color-border);
		width: 108px;
		overflow-y: auto;
	}
}
</style>

<style lang="scss">
/* Confined to this editor modal via its own class (which merges onto the
   modal mask). Gives the public editor the same dark header bar as the
   authenticated VisibleElementsNext. */
.pve-editor-modal {
	/* The app sets --header-height:0 on public pages (no Nextcloud top bar), so
	   NcModal reserved no room for its absolute header and the dark bar overlapped
	   the document. Giving the modal a real header height makes NcModal offset the
	   content via its own calc(100% - var(--header-height)) — same as staging. */
	--header-height: 50px;
}

.pve-editor-modal .modal-header {
	background-color: #0f172a !important;
}

.pve-editor-modal .modal-header__name {
	color: #fff !important;
}

.pve-editor-modal .modal-header .icons-menu button {
	color: #fff !important;
}
</style>

