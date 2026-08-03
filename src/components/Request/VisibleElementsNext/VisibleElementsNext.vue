<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  VisibleElementsNext — reworked "Signature positions" editor.

  Interaction: tap a signer to ISOLATE/review them (others dim); "Add position"
  arms tap-to-place; "Show all" clears the focus. No Sign button — placement only.
-->
<template>
	<NcModal v-if="modal"
		:name="t('libresign', 'Signature positions')"
		:close-button-contained="false"
		:close-button-outside="true"
		size="full"
		@close="closeModal">
		<div v-if="filesStore.loading">
			<NcLoadingIcon :size="64" :name="t('libresign', 'Loading …')" />
		</div>
		<div v-else class="ven" :class="{ 'ven--mobile': isMobile }">
			<aside v-if="!isMobile" class="ven__sidebar">
				<div v-if="!isMobile" class="ven__heading">
					<h2 class="ven__name">{{ document.name }}</h2>
					<span v-if="statusLabel" class="ven__status" :title="statusLabel">
						<span class="ven__status-dot" :style="{ backgroundColor: statusColor }" aria-hidden="true" />
						{{ statusLabel }}
					</span>
				</div>

				<div v-if="!isMobile && pdfReady" class="ven__count">
					<span class="ven__count-label">{{ t('libresign', 'Signers') }}</span>
					<VeCompletenessPill :placed="placedCount" :total="signerCount" />
				</div>

				<div v-if="!pdfReady" class="ven__preparing">
					<NcLoadingIcon :size="32"
						:name="t('libresign', 'Getting PDF ready for signature placement…')" />
					<p>{{ t('libresign', 'Please wait while we prepare the document.') }}</p>
				</div>

				<div v-else class="ven__signers">
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

				<div class="ven__actions">
					<VeButton v-if="canSave"
						:variant="anyEmpty ? 'secondary' : 'primary'"
						wide
						:disabled="!pdfReady || loading || mode === 'place'"
						@click="save()">
						{{ anyEmpty ? t('libresign', 'Save anyway') : t('libresign', 'Save') }}
					</VeButton>
				</div>
			</aside>

			<div class="ven__center">
				<button
					type="button"
					class="ven__help"
					:class="{ 'ven__help--offset': !isMobile && mode !== 'browse' }"
					:aria-label="t('libresign', 'How signature positions work')"
					:title="t('libresign', 'How it works')"
					@click="showHelp = true">
					<svg class="ven__help-icon" width="30" height="30" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
						<path :d="mdiHelpCircleOutline" />
					</svg>
				</button>

				<VePlacementBanner v-if="!isMobile && pdfReady && mode !== 'browse'"
					class="ven__banner"
					:mode="mode"
					:active-signer-name="activeSignerName"
					:show-edit-toggle="false"
					@cancel="stopAddSigner"
					@show-all="onShowAll"
					@toggle-edit="toggleEdit" />

				<div class="ven__canvas">
					<div v-if="!pdfReady" class="ven__overlay">
						<NcLoadingIcon :size="64"
							:name="t('libresign', 'Preparing document for signature placement…')" />
					</div>
					<PdfEditor v-if="!filesStore.loading && pdfEditorFiles.length > 0"
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
				class="ven__rail"
				:pages="railPages"
				:signers-by-page="signersByPage"
				:current-global-page="currentGlobalPage"
				:color-for="railColorFor"
				@select-page="onSelectPage" />

			<VeSignerSheet v-if="isMobile && pdfReady"
				:document-name="document.name || ''"
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

			<NcModal v-if="showHelp"
				:name="t('libresign', 'How signature positions work')"
				size="small"
				@close="showHelp = false">
				<div class="ven-help">
					<h3 class="ven-help__title">{{ t('libresign', 'Placing signatures') }}</h3>
					<ol class="ven-help__steps">
						<li v-for="(step, index) in helpSteps" :key="index">{{ step }}</li>
					</ol>
				</div>
			</NcModal>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PdfEditor from './PdfEditorNext.vue'
import VeButton from './VeButton.vue'
import VeCompletenessPill from './VeCompletenessPill.vue'
import VePageRail from './VePageRail.vue'
import VePlacementBanner from './VePlacementBanner.vue'
import VeSignerSelect from './VeSignerSelect.vue'
import VeSignerSheet from './VeSignerSheet.vue'
import { mdiHelpCircleOutline } from '@mdi/js'
import { useVisibleElementsNext } from './composables/useVisibleElementsNext'

defineOptions({
	name: 'VisibleElementsNext',
})

const {
	filesStore,
	pdfEditor,
	t,
	modal,
	loading,
	mode,
	focusedSignerId,
	placing,
	signerSelected,
	pdfReadOnly,
	document,
	documentFiles,
	pdfReady,
	pdfEditorFiles,
	pdfFiles,
	pdfFileNames,
	canSave,
	status,
	statusLabel,
	statusColor,
	isDraft,
	placedCount,
	countBySigner,
	signerList,
	signerCount,
	pdfEditorSigners,
	activeSignRequestId,
	activeSignerName,
	emptySignerNames,
	anyEmpty,
	partialEmpty,
	emptyWarning,
	overlapText,
	overlappingSignerIds,
	railPages,
	signersByPage,
	currentGlobalPage,
	colorFor,
	railColorFor,
	isSelectedSigner,
	colorMap,
	overlappingIds,
	highlightId,
	showHelp,
	helpSteps,
	isMobile,
	getPdfElements,
	showModal,
	fetchFiles,
	buildFilePagesMap,
	seedPlacements,
	closeModal,
	getPageHeightForFile,
	updateSigners,
	onSelectSigner,
	onReviewSigner,
	onShowAll,
	handleAddingEnded,
	stopAddSigner,
	toggleEdit,
	onSheetDone,
	handleObjectClick,
	onSelectPage,
	onDeleteSigner,
	handleDeleteSigner,
	save,
	buildVisibleElements,
	syncFromEditor,
	handlePdfReady,
} = useVisibleElementsNext()

defineExpose({
	filesStore,
	pdfEditor,
	t,
	modal,
	loading,
	mode,
	focusedSignerId,
	placing,
	signerSelected,
	pdfReadOnly,
	document,
	pdfFiles,
	pdfFileNames,
	canSave,
	status,
	statusLabel,
	isDraft,
	placedCount,
	countBySigner,
	getPdfElements,
	showModal,
	fetchFiles,
	buildFilePagesMap,
	seedPlacements,
	closeModal,
	getPageHeightForFile,
	updateSigners,
	onSelectSigner,
	onReviewSigner,
	onShowAll,
	stopAddSigner,
	toggleEdit,
	onSelectPage,
	onDeleteSigner,
	save,
	buildVisibleElements,
	syncFromEditor,
})
</script>
<style src="./styles/VisibleElementsNext.scss" lang="scss" scoped></style>
