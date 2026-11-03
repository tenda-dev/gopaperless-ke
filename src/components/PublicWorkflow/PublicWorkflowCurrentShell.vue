<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Canonical anonymous document-preparation workspace.

  Reuses the public workflow's existing stores, editing components,
  and persistence behaviour while providing an independent presentation
  layer. Authenticated components are reused only where they are
  presentation-only; authenticated signing state and logic remain separate.

  Handles draft preparation only.
-->
<template>
	<PublicShell fill hide-footer>
		<div class="pw-shell">
			<!-- loading -->
			<div v-if="phase === 'loading'" class="pw-shell__state" aria-live="polite">
				<NcLoadingIcon :size="40" :name="t('libresign', 'Loading…')" />
			</div>

			<!-- expired / invalid -->
			<div v-else-if="phase === 'expired'" class="pw-shell__state">
				<NcIconSvgWrapper class="pw-shell__state-ic" :path="mdiClockAlertOutline" :size="40" />
				<h2 class="pw-shell__state-title">{{ t('libresign', 'This draft has expired') }}</h2>
				<p class="pw-muted">{{ t('libresign', 'This draft has expired and is no longer available — you can start a fresh one.') }}</p>
				<button type="button" class="pw-btn pw-btn--primary" @click="onStart">{{ t('libresign', 'Start a new draft') }}</button>
			</div>

			<!-- error -->
			<div v-else-if="phase === 'error'" class="pw-shell__state">
				<p class="pw-muted">{{ errorMessage || t('libresign', 'Something went wrong.') }}</p>
				<button type="button" class="pw-btn" @click="resume">{{ t('libresign', 'Try again') }}</button>
			</div>

			<!-- idle: compact centred hero -->
			<div v-else-if="phase === 'idle'" class="pw-shell__state pw-hero">
				<h1 class="pw-shell__state-title">{{ t('libresign', 'Prepare a document to sign') }}</h1>
				<p class="pw-muted">{{ t('libresign', 'No account needed. Add your document, the people who sign and where they sign — it stays a private draft until you choose to send it.') }}</p>
				<ol class="pw-steps-preview" aria-hidden="true">
					<li><span class="pw-steps-preview__n">1</span>{{ t('libresign', 'Prepare') }}</li>
					<li class="pw-steps-preview__sep">→</li>
					<li><span class="pw-steps-preview__n">2</span>{{ t('libresign', 'Fields') }}</li>
					<li class="pw-steps-preview__sep">→</li>
					<li><span class="pw-steps-preview__n">3</span>{{ t('libresign', 'Review') }}</li>
				</ol>
				<button type="button" class="pw-btn pw-btn--primary" @click="onStart">{{ t('libresign', 'Start') }}</button>
				<p class="pw-trust">{{ t('libresign', 'No account needed · Private draft · Nothing sent until you choose') }}</p>
			</div>

			<!-- ready: three-pane workspace -->
			<div v-else-if="phase === 'ready'" class="pwn" :class="{ 'is-doc-hidden': !docPeek }">
				<!-- mobile only: scrim behind the opt-in document sheet -->
				<div class="pwn__scrim" aria-hidden="true" @click="docPeek = false" />

				<!-- Opens the document pane as a mobile bottom sheet. -->
				<button v-if="!docPeek" type="button" class="pwn__peek-fab" :aria-label="t('libresign', 'Preview document')" @click="docPeek = true">
					<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" />
				</button>

				<div class="pwn__ws">
					<!-- document stage: persistent pane (desktop) / opt-in bottom-sheet (mobile) -->
					<div class="pwn__stage">
						<span class="pwn__grab" aria-hidden="true" />
						<button type="button" class="pwn__sheet-close" :aria-label="t('libresign', 'Close document')" @click="docPeek = false">
							<NcIconSvgWrapper :path="mdiClose" :size="18" />
						</button>

						<!-- Conjoined pill (pw-stagectl__bar) is disabled while the content area
						     is rebuilt around the authenticated flow's title/Documents/card
						     composition below — NOT deleted, kept for later reactivation. -->
						<PwStageControl v-if="false"
							:document="(doc as any)"
							:has-document="hasDocument"
							:active="true"
							:name="documentName"
							:pages="documentPages"
							:expiry="expiresLabel"
							:signer-count="enteredSignerCount"
							:status-label="stageStatusLabel"
							:status-variant="stageStatusVariant" />

						<!-- Pre-upload: no title here — the rail's dropzone already says "Add
						     your document"; repeating that above the empty pane was the misleading
						     copy flagged in review. -->
						<div v-if="!hasDocument" class="pwn__stage-empty">
							<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="28" />
							<p class="pwn__stage-empty-title">{{ t('libresign', 'Your document will appear here') }}</p>
							<p class="pwn__stage-empty-sub">{{ t('libresign', 'Add a document and it stays in view through every step.') }}</p>
						</div>

						<!-- Post-upload: title mirrors Request.vue's "Request Signature", then the
						     Documents subheader + card, same composition as the authenticated flow. -->
						<template v-else>
							<!-- total-width-minus-sidebar centered, not screen-centered — .pwn__stage
							     IS that remaining width already (grid's stage area), so centering a
							     capped column inside it lands here automatically. -->
							<div class="pwn__stage-content">
								<div class="pwn__stage-head">
									<h1 class="pwn__stage-title">{{ t('libresign', 'Request signature') }}</h1>
									<p class="pwn__stage-sub">{{ t('libresign', 'Add the people who need to sign, then place their signature fields.') }}</p>
								</div>

								<!-- Keep both panes mounted so switching tabs preserves the PDF preview state. -->
								<div class="pwn__doctabs" role="tablist">
									<span class="pwn__doctabs-ind" aria-hidden="true" :class="{ 'is-preview': docTab === 'preview' }" />
									<button type="button"
										role="tab"
										class="pwn__doctabs-tab"
										:class="{ 'is-active': docTab === 'summary' }"
										:aria-selected="docTab === 'summary'"
										@click="docTab = 'summary'">
										<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="15" />
										{{ t('libresign', 'Summary') }}
									</button>
									<button type="button"
										role="tab"
										class="pwn__doctabs-tab"
										:class="{ 'is-active': docTab === 'preview' }"
										:aria-selected="docTab === 'preview'"
										@click="docTab = 'preview'">
										<NcIconSvgWrapper :path="mdiEyeOutline" :size="15" />
										{{ t('libresign', 'Preview') }}
									</button>
								</div>

								<div v-show="docTab === 'summary'" class="pwn__doctabs-pane">
									<div class="pwn__docs-head">
										<h2 class="pwn__docs-head-title">{{ t('libresign', 'Documents') }}</h2>
										<span class="pwn__docs-head-count">1</span>
									</div>
									<PwDocumentCard
										:name="documentName"
										:pages="documentPages"
										:expiry="expiresLabel"
										:signer-count="enteredSignerCount"
										:status-label="stageStatusLabel"
										:status-variant="stageStatusVariant"
										@view="docTab = 'preview'" />
								</div>

								<PwDocumentInlinePreview v-show="docTab === 'preview'" class="pwn__doctabs-pane" :active="docTab === 'preview'" :document="doc" />
							</div>
						</template>
					</div>

					<!-- task rail -->
					<section class="pwn__rail">
						<div class="pwn__rail-scroll">
							<!-- top of the rail: Back (contextual) + a persistent title, mirrors
							     the authenticated sidebar's "Document action centre" panel name. -->
							<div class="pwn__pnav">
								<button v-if="panelNav.back" type="button" class="pwn__back" @click="panelNav.back()">
									<NcIconSvgWrapper :path="mdiArrowLeft" :size="15" />
									<!-- {{ t('libresign', 'Back') }} -->
								</button>
								<span class="pwn__pnav-title">{{ t('libresign', 'Document action centre') }}</span>
								<!-- disabled, not deleted — this slot is now "Document action centre" above -->
								<span v-if="false" class="pwn__stepcount">{{ panelNav.step }}</span>
							</div>

							<!-- document details strip, mirrors WorkflowHeader.vue — icon, status
							     pill, name, requester line — followed by its own soft divider.
							     Only once a document exists; nothing to summarise before that. -->
							<template v-if="hasDocument">
								<div class="pwn__docinfo">
									<div class="pwn__docinfo-icon">
										<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" />
									</div>
									<div class="pwn__docinfo-main">
										<span class="pwn__docinfo-pill" :class="`pwn__docinfo-pill--${stageStatusVariant}`">
											<span class="pwn__docinfo-dot" aria-hidden="true" />
											{{ docinfoPillText }}
										</span>
										<h2 class="pwn__docinfo-name">{{ documentName }}</h2>
										<p v-if="requesterEmailValid" class="pwn__docinfo-requester">
											{{ t('libresign', 'Requesting as {email}', { email: requesterEmail }) }}
											<button type="button" class="pwn__docinfo-change" @click="goToPrepareIdentify">{{ t('libresign', 'Change?') }}</button>
										</p>
									</div>
								</div>
								<div class="pwn__docinfo-divider" />
							</template>

							<!-- numbered stepper (reused directly from the authenticated workflow;
							     now Email / Add signers / Setup positions / Request). -->
							<WorkflowStepper class="pwn__stepper" :steps="workflowSteps" clickable @select="goToStep" />

							<!-- PREPARE -->
							<template v-if="activeView === 'prepare'">
								<!-- UPLOAD -->
								<template v-if="prepareView === 'upload'">
									<h2 class="pwn__title">{{ t('libresign', 'Add your document') }}</h2>
									<p class="pwn__sub">{{ t('libresign', 'Upload the PDF you need signed. It becomes a private draft — nothing is sent or signed yet.') }}</p>
									<PwReassurance />
									<template v-if="!hasDocument">
										<div v-if="!uploading"
											class="pw-drop"
											:class="{ 'is-drag': dragOver }"
											role="button"
											tabindex="0"
											:aria-label="t('libresign', 'Choose a PDF, or drag one here')"
											@click="fileInput?.click()"
											@keydown.enter.prevent="fileInput?.click()"
											@keydown.space.prevent="fileInput?.click()"
											@dragover.prevent="dragOver = true"
											@dragleave="dragOver = false"
											@drop.prevent="onDrop">
											<span class="pw-drop__ic"><NcIconSvgWrapper :path="mdiTrayArrowUp" :size="26" /></span>
											<span class="pw-drop__title">{{ t('libresign', 'Drag a PDF here, or click to choose') }}</span>
											<span class="pw-drop__sub">{{ expiresLabel ? t('libresign', 'Kept private · {e}.', { e: expiresLabel }) : t('libresign', 'Kept private — nothing is sent.') }}</span>
											<span class="pw-drop__pill">{{ t('libresign', 'PDF · {n}MB max', { n: MAX_FILE_SIZE_MB }) }}</span>
										</div>
										<div v-else class="pw-drop pw-drop--busy" aria-live="polite">
											<NcLoadingIcon :size="32" :name="t('libresign', 'Uploading…')" />
											<span class="pw-drop__title">{{ t('libresign', 'Uploading…') }}</span>
											<div class="pw-progress"><span :style="{ width: progress + '%' }" /></div>
										</div>
										<input ref="fileInput" type="file" accept="application/pdf" class="pw-hidden-file" @change="onPick">
										<p v-if="uploadError" class="pw-err" role="alert">{{ uploadError }}</p>
									</template>
									<p v-else class="pwn__done">
										<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="16" />
										<span>{{ t('libresign', 'Your document is shown on the left. Continue to add the people who sign.') }}</span>
									</p>
								</template>

								<!-- IDENTIFY -->
								<template v-else-if="prepareView === 'identify'">
									<h2 class="pwn__title">{{ t('libresign', 'What’s your email?') }}</h2>
									<p class="pw-muted">{{ t('libresign', 'Your contact email for this draft. It doesn’t verify you, make an account, or send anything.') }}</p>
									<div class="pw-emailfield">
										<label class="pw-emailfield__label" for="pwn-req-email">{{ t('libresign', 'Your email') }}</label>
										<input id="pwn-req-email"
											ref="reqEmailInput"
											v-model.trim="requesterEmail"
											type="email"
											class="pw-emailfield__input"
											:class="{ 'is-invalid': requesterEmailShowError }"
											autocomplete="email"
											inputmode="email"
											:aria-invalid="requesterEmailShowError || undefined"
											aria-describedby="pwn-req-note pwn-req-err"
											:placeholder="t('libresign', 'name@example.com')"
											@blur="requesterTouched = true"
											@keyup.enter="onIdentifyContinue">
										<p v-if="requesterEmailShowError" id="pwn-req-err" class="pw-err">{{ t('libresign', 'Enter a valid email address, like name@example.com.') }}</p>
									</div>
									<div class="pw-selfsign">
										<label class="pw-selfsign__check">
											<input v-model="selfSign" type="checkbox" :disabled="!requesterEmailValid">
											<span>{{ selfSignLabel }}</span>
										</label>
										<p id="pwn-req-note" class="pw-selfsign__note">{{ t('libresign', 'You’ll place a signature field for yourself too. Leave it off to request signatures from others only.') }}</p>
									</div>
								</template>

								<!-- ADD SIGNERS -->
								<template v-else>
									<!-- <h2 class="pwn__title">{{ addSignersTitle }}</h2>
									<PwContextualHint v-if="enteredSignerCount === 0">
										{{ t('libresign', 'Add the people who will sign. We only use these details when you choose to send — nothing is sent now.') }}
									</PwContextualHint> -->
									<div class="pwn__signers-head">
										<span class="pwn__signers-head-label">{{ t('libresign', 'Signers') }}</span>
										<button type="button" class="pwn__signers-head-add" @click="openAdd(null)">
											<NcIconSvgWrapper :path="mdiPlus" :size="15" />
											{{ t('libresign', 'Add signer') }}
										</button>
									</div>
									<div class="pwn__signers" :class="{ 'is-busy': signersSaving }" :aria-busy="signersSaving">
										<PwSignerList :signers="signers" :requester-email="requesterSignerEmail" layout="row" hide-add @add="openAdd(null)" @edit="openAdd($event)" @remove="removeSigner($event)" />
									</div>
									<div v-if="sponsorshipEnabled" class="pw-help" :class="{ 'is-open': costsPanelOpen }">
										<button type="button" class="pw-help__toggle" :aria-expanded="costsPanelOpen" @click="costsPanelOpen = !costsPanelOpen">
											<strong>{{ t('libresign', 'Cover your signers’ signing costs') }}</strong>
											<NcIconSvgWrapper :path="costsPanelOpen ? mdiChevronUp : mdiChevronDown" :size="16" />
										</button>
										<template v-if="costsPanelOpen">
											<p class="pw-help__copy">
												{{ t('libresign', 'Choose who to cover, and we’ll use your available certified signatures to pay for their eligible signing costs.') }}
											</p>
											<p class="pw-help__note">
												{{ t('libresign', 'The ★ on a signer means you’re covering their cost. Nothing is sent until you choose to continue.') }}
											</p>
										</template>
									</div>
									<p class="pwn__autosave" role="status" aria-live="polite">
										<template v-if="signersSaving">
											<NcLoadingIcon :size="14" /> {{ t('libresign', 'Saving…') }}
										</template>
										<template v-else-if="signersError">
											<span class="pw-err">{{ signersError }}</span>
											<button type="button" class="pwn__retry" @click="persistSigners">{{ t('libresign', 'Retry') }}</button>
										</template>
										<template v-else-if="hasSavedSigners">
											<NcIconSvgWrapper class="pwn__autosave-ic" :path="mdiCheckCircleOutline" :size="15" /> {{ t('libresign', 'Saved · nothing is sent') }}
										</template>
										<template v-else>
											<span class="pw-savenote">{{ t('libresign', 'Changes save automatically · nothing is sent') }}</span>
										</template>
									</p>
								</template>
							</template>

							<!-- FIELDS -->
							<template v-else-if="activeView === 'fields'">
								<!-- <template v-if="!hasPlacedFields">
									<h2 class="pwn__title">{{ t('libresign', 'Place signature fields') }}</h2>
									<p class="pw-muted">{{ t('libresign', 'Put each person’s signature where it belongs. They appear on the document as you place them.') }}</p>
								</template> -->
								<PwContextualHint v-if="!hasSavedSigners">
									{{ t('libresign', 'Save at least one signer before placing fields.') }}
								</PwContextualHint>
								<div v-else-if="hasPlacedFields" class="pw-sumrow">
									<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="18" />
									<span class="pw-sumrow__text">
										<span class="pw-sumrow__k">{{ t('libresign', 'Signature fields') }}</span>
										<span class="pw-sumrow__v">{{ fieldsPlacedSummary }}</span>
									</span>
									<button type="button" class="pw-sumrow__edit" @click="openEditor">{{ t('libresign', 'Edit') }}</button>
								</div>
								<PwActionCard
									v-else
									:icon="mdiFormatColorFill"
									:title="t('libresign', 'Signature fields')"
									:description="t('libresign', 'Place a signature field for each signer before continuing.')"
									:primary-label="t('libresign', 'Place signature fields')"
									:disabled="!canEnterFields"
									@primary="openEditor" />
								<PwReassurance />
							</template>

							<!-- REVIEW -->
							<template v-else>
								<h2 class="pwn__title">{{ t('libresign', 'Review your draft') }}</h2>
								<ul class="pw-summary">
									<li class="pw-sumrow">
										<NcIconSvgWrapper :path="mdiAccountGroupOutline" :size="18" />
										<span class="pw-sumrow__text">
											<span class="pw-sumrow__k">{{ signersCountLabel }}</span>
											<span v-if="signerNames" class="pw-sumrow__v">{{ signerNames }}</span>
										</span>
										<button type="button" class="pw-sumrow__edit" @click="goToPrepareSigners">{{ t('libresign', 'Edit') }}</button>
									</li>
									<li class="pw-sumrow">
										<NcIconSvgWrapper :path="mdiFormatColorFill" :size="18" />
										<span class="pw-sumrow__text">
											<span class="pw-sumrow__k">{{ fieldsPlacedLabel }}</span>
										</span>
										<button type="button" class="pw-sumrow__edit" @click="goToView('fields')">{{ t('libresign', 'Adjust') }}</button>
									</li>
								</ul>
								<p class="pw-notsent">
									<strong>{{ t('libresign', 'Nothing has been sent, signed, or emailed.') }}</strong>
									{{ expiresLabel
										? t('libresign', 'Your draft is saved; {e}. Keep editing or come back before then.', { e: expiresLabel })
										: t('libresign', 'Your draft is saved; Keep editing or come back before it expires.') }}
								</p>
								<div class="pw-claim">
									<!-- Email identity is already shown (and editable) in the details-strip
									     above, once requesterEmailValid is true — restating it here would just
									     duplicate it. This recap only resurfaces when there's actually something
									     to fix, since the details-strip has nothing to show in that case. -->
									<div v-if="!requesterEmailValid" class="pw-claim__recap">
										<span class="pw-claim__recap-k">{{ t('libresign', 'Sending as') }}</span>
										<span class="pw-claim__recap-missing">{{ t('libresign', 'Add your email to continue') }}</span>
										<button type="button" class="pw-sumrow__edit" @click="goToPrepareIdentify">{{ t('libresign', 'Add your email') }}</button>
									</div>
									<p v-if="claimError" class="pw-err">{{ claimError }}</p>
								</div>
							</template>

						<!-- forward move, in-flow (mirrors WorkflowActions: just the last item in
						     the authenticated panel's own scroll, not a separate pinned bar) — sticky-
						     pinned on mobile only for now, see @container below. -->
						<div class="pwn__cta">
							<template v-if="activeView === 'prepare' && prepareView === 'upload'">
								<button type="button" class="pw-btn pw-btn--primary pwn__grow" :disabled="!hasDocument" @click="prepareView = 'identify'">
									{{ t('libresign', 'Continue') }} →
								</button>
							</template>
							<template v-else-if="activeView === 'prepare' && prepareView === 'identify'">
								<span v-if="identifyError" class="pw-err">{{ identifyError }}</span>
								<button type="button"
									class="pw-btn pw-btn--primary pwn__grow"
									:disabled="savingRequester || !requesterEmailValid"
									@click="onIdentifyContinue">
									{{ savingRequester ? t('libresign', 'Saving…') : t('libresign', 'Continue') }} →
								</button>
							</template>
							<template v-else-if="activeView === 'prepare'">
								<button type="button"
									class="pw-btn pw-btn--primary pwn__grow"
									:disabled="!canEnterFields"
									:title="signersSaving ? t('libresign', 'Saving your signers…') : (!hasSavedSigners ? t('libresign', 'Add a signer to continue') : undefined)"
									@click="goToView('fields')">
									{{ t('libresign', 'Continue to fields') }} →
								</button>
							</template>
							<template v-else-if="activeView === 'fields'">
								<button type="button"
									class="pw-btn pw-btn--primary pwn__grow"
									:disabled="!hasPlacedFields"
									:title="!hasPlacedFields ? t('libresign', 'Place a signature field to continue') : undefined"
									@click="goToView('review')">
									{{ t('libresign', 'Review') }} →
								</button>
							</template>
							<template v-else>
								<button type="button"
									class="pw-btn pw-btn--primary pwn__grow"
									:disabled="signingIn || !requesterEmailValid"
									@click="signInToSend">
									{{ signingIn ? t('libresign', 'Redirecting…') : t('libresign', 'Continue') }} →
								</button>
							</template>
						</div>
						</div>
					</section>
				</div>
			</div>

			<PwAddSignerDialog
				:open="addOpen"
				:editing="editIndex !== null"
				:existing-emails="otherSigners.map((s) => s.email)"
				:initial-email="editRow?.email || ''"
				:initial-name="editRow?.displayName || ''"
				:initial-sponsored="editRow ? (editRow.requesterSponsored === true) : true"
				:initial-description="editRow?.description || ''"
				@update:open="addOpen = $event"
				@add="onAddSigner" />

			<PublicVisibleElements ref="editor" />
		</div>
	</PublicShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
	mdiAccountGroupOutline,
	mdiArrowLeft,
	mdiClose,
	mdiCheckCircleOutline,
	mdiChevronDown,
	mdiChevronUp,
	mdiClockAlertOutline,
	mdiEyeOutline,
	mdiFile,
	mdiFileDocumentOutline,
	mdiFormatColorFill,
	mdiPlus,
	mdiTrayArrowUp,
	mdiVectorSquareEdit
} from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import WorkflowStepper from '../RightSidebar/Workflow/WorkflowStepper.vue'
import type { WorkflowStep } from '../../composables/useWorkflowState'
import PwActionCard from './PwActionCard.vue'
import PwAddSignerDialog from './PwAddSignerDialog.vue'
import PwContextualHint from './PwContextualHint.vue'
import PwDocumentCard from './PwDocumentCard.vue'
import PwDocumentInlinePreview from './PwDocumentInlinePreview.vue'
import PwReassurance from './PwReassurance.vue'
import PwSignerList from './PwSignerList.vue'
import PwStageControl from './PwStageControl.vue'
import PublicVisibleElements from './PublicVisibleElements.vue'
import PublicShell from '../PublicShell.vue'
import { usePublicWorkflow, type SignerDraft } from '../../store/publicWorkflow'
import { buildSignerPayload, isPlausibleEmail } from './signerPayload'
import { MAX_FILE_SIZE_MB, validatePublicUploadFile } from './uploadValidation'

defineOptions({ name: 'PublicWorkflowCurrentShell' })

const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as { sponsorship_enabled?: boolean }
).sponsorship_enabled ?? false

// Upload cap + PDF validation live in ./uploadValidation so the drop zone here and
// the /p/upload landing's direct upload share one rule set. MAX_FILE_SIZE_MB is
// still referenced by the "25MB max" copy below.

type View = 'prepare' | 'fields' | 'review'

const {
	phase, status, expiresAt, hasDocument, errorMessage,
	resume, start, upload, saveSigners, saveRequester,
	signers, visibleElements, document: doc,
	requesterEmail: storeRequesterEmail, requesterIsSigner: storeRequesterIsSigner,
} = usePublicWorkflow()

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const progress = ref(0)
const uploadError = ref('')
const signersSaving = ref(false)
const signersError = ref('')
const addOpen = ref(false)
// Controls the mobile document-pane sheet; desktop keeps the document visible.
const docPeek = ref(false)

// Controls document content independently of docPeek, which toggles the mobile pane.
const docTab = ref<'summary' | 'preview'>('summary')
const dragOver = ref(false)
const editIndex = ref<number | null>(null)
const editor = ref<InstanceType<typeof PublicVisibleElements> | null>(null)
const activeView = ref<View>('prepare')
const prepareView = ref<'upload' | 'identify' | 'signers'>('upload')
const reqEmailInput = ref<HTMLInputElement | null>(null)
const savingRequester = ref(false)
const identifyError = ref('')
const requesterTouched = ref(false)
const justResumed = ref(false)
const startedThisSession = ref(false)
// One-shot guard: on the first FORWARD entry to an empty signers step, the only
// possible action is to open the add-signer dialog, so we open it once. Set true
// after firing so it never re-opens after a cancel, and never on back-navigation
// or resume (those paths don't run the open below).
const signersAutoPrompted = ref(false)
const requesterEmail = ref('')
// Email-step self-sign INTENT — explicit, never inferred from email matching.
// Edited by the checkbox, committed on Continue, hydrated from the persisted
// requesterIsSigner on resume.
const selfSign = ref(false)
// The email under which the requester is CURRENTLY materialised as a signer
// (empty when they are not a signer). Tracked explicitly so a re-key removes the
// right row and a coincidental email match is never mistaken for the requester.
const requesterSignerEmail = ref('')
const signingIn = ref(false)
const claimError = ref('')
const oidcLoginUrl = (loadState('libresign', 'public_upload_oidc_login_url', '') as string) || ''

async function signInToSend(): Promise<void> {
	claimError.value = ''
	signingIn.value = true
	try {
		await saveRequester(requesterEmail.value.trim() || null, selfSign.value)
		const fallback = generateUrl('/login') + '?redirect_url=' + encodeURIComponent(generateUrl('/apps/libresign/f/request'))
		window.location.href = oidcLoginUrl || fallback
	} catch {
		claimError.value = t('libresign', 'Could not save your details. Please try again.')
		signingIn.value = false
	}
}

watch(storeRequesterEmail, (v) => { if (v != null && v !== '') { requesterEmail.value = v } }, { immediate: true })
// Self-sign intent is taken from the PERSISTED requesterIsSigner, not from any
// email match — that is the whole point of #1.
watch(storeRequesterIsSigner, (v) => { selfSign.value = v === true }, { immediate: true })

onMounted(async () => {
	await resume()
	if (phase.value === 'ready' && hasDocument.value) {
		justResumed.value = !startedThisSession.value
		prepareView.value = storeRequesterEmail.value ? 'signers' : 'identify'
		// Reconstruct which signer row is the requester from authoritative state:
		// explicit intent (persisted) + the server's set. Email is only the join key.
		const reqLc = (storeRequesterEmail.value ?? '').trim().toLowerCase()
		requesterSignerEmail.value = (selfSign.value && reqLc !== '' && signers.value.some((s) => s.email.trim().toLowerCase() === reqLc))
			? (storeRequesterEmail.value ?? '').trim()
			: ''
	} else {
		prepareView.value = 'upload'
	}
})

watch(hasDocument, (has) => {
	if (!has) { activeView.value = 'prepare'; prepareView.value = 'upload' }
})

// --- derived ---
const documentName = computed(() => (doc.value as { name?: string } | null)?.name || t('libresign', 'Your document'))
const documentPages = computed(() => Number((doc.value as { metadata?: { p?: number } } | null)?.metadata?.p ?? 0))
const requesterEmailValid = computed(() => isPlausibleEmail(requesterEmail.value))
const requesterEmailShowError = computed(() => requesterTouched.value && requesterEmail.value.trim().length > 0 && !requesterEmailValid.value)
const selfSignLabel = computed(() => (requesterEmailValid.value
	? t('libresign', 'Add {email} as a signer', { email: requesterEmail.value.trim() })
	: t('libresign', 'Add yourself as a signer')))
const hasSavedSigners = computed(() => signers.value.some((s) => s.signRequestId != null))
const hasPlacedFields = computed(() => visibleElements.value.length > 0)
// Fields may be entered only when the signer set is settled: at least one saved
// signer AND no save in flight or failed (#2/#4 — never advance on unsaved work).
const canEnterFields = computed(() => hasSavedSigners.value && !signersSaving.value && signersError.value === '')
const enteredSignerCount = computed(() => signers.value.filter((s) => s.email.trim()).length)
// "Add signers" at first; "Add more signers" once anyone (you included) is in.
const addSignersTitle = computed(() => (enteredSignerCount.value > 0
	? t('libresign', 'Add more signers')
	: t('libresign', 'Add signers')))
const signerNames = computed(() => signers.value
	.filter((s) => s.email.trim())
	.map((s) => s.displayName.trim() || s.email.trim())
	.join(' · '))
const signersCountLabel = computed(() => n('libresign', '%n signer', '%n signers', enteredSignerCount.value))
const fieldsPlacedLabel = computed(() => n('libresign', '%n signature field placed', '%n signature fields placed', visibleElements.value.length))
const fieldsPlacedSummary = computed(() => {
	const fields = n('libresign', '%n field', '%n fields', visibleElements.value.length)
	const signers = n('libresign', '%n signer', '%n signers', enteredSignerCount.value)
	return t('libresign', '{fields} placed across {signers}', { fields, signers })
})
const editRow = computed<SignerDraft | null>(() => (editIndex.value !== null ? signers.value[editIndex.value] ?? null : null))
const otherSigners = computed(() => signers.value.filter((_, i) => i !== editIndex.value && signers.value[i].email.trim()))

const stepLabels = computed(() => [
	t('libresign', 'Email'),
	t('libresign', 'Add signers'),
	t('libresign', 'Setup positions'),
	t('libresign', 'Review'),
])
// Upload has no step of its own — it's the gate before this stepper is even
// shown (see hasDocument in the template) — so it falls back to Email (0).
const activeStepIndex = computed(() => {
	if (activeView.value === 'fields') { return 2 }
	if (activeView.value === 'review') { return 3 }
	if (prepareView.value === 'signers') { return 1 }
	return 0
})

// Reusing WorkflowStepper (RightSidebar/Workflow) directly means feeding it the
// SAME WorkflowStep[] shape the authenticated rail uses — derived from the
// existing activeStepIndex/stepReachable logic above, not a second source of
// truth. `locked` (not just `upcoming`) once a step isn't reachable yet, same
// semantic distinction the authenticated rail makes.
const workflowSteps = computed<WorkflowStep[]>(() => stepLabels.value.map((label, i) => ({
	id: String(i),
	label,
	completed: i < activeStepIndex.value && stepReachable(i),
	active: i === activeStepIndex.value,
	status: i === activeStepIndex.value
		? 'current'
		: i < activeStepIndex.value && stepReachable(i)
			? 'complete'
			: stepReachable(i) ? 'upcoming' : 'locked',
})))

// Stage-control status pill text/variant, tracked to the step (mirrors the
// settled mockup: no label on Prepare, "Set positions" on Fields, "Ready" on
// Review) — PwDocumentCard falls back to its default "View" button when empty.
const stageStatusLabel = computed(() => {
	if (activeView.value === 'fields') { return t('libresign', 'Set positions') }
	if (activeView.value === 'review') { return t('libresign', 'Ready') }
	return ''
})
const stageStatusVariant = computed<'draft' | 'purple' | 'ready'>(() => {
	if (activeView.value === 'fields') { return 'purple' }
	if (activeView.value === 'review') { return 'ready' }
	return 'draft'
})

const expiresLabel = computed(() => {
	if (!expiresAt.value) { return '' }
	const ms = new Date(expiresAt.value).getTime() - Date.now()
	if (!Number.isFinite(ms) || ms <= 0) { return '' }
	const minutes = Math.round(ms / 60_000)
	// Show minutes under an hour so a short (e.g. 1h) window never rounds to "0h".
	if (minutes < 60) {
		return t('libresign', 'Expires in {m}m', { m: Math.max(1, minutes) })
	}
	return t('libresign', 'Expires in {h}h', { h: Math.round(minutes / 60) })
})

// Details-strip pill text: status (or "Draft" when the step has no label of
// its own) plus expiry on one line, replacing the separate "We kept your
// draft" banner that used to sit below the stepper.
const docinfoPillText = computed(() => {
	const label = stageStatusLabel.value || t('libresign', 'Draft')
	return expiresLabel.value ? `${label} · ${expiresLabel.value}` : label
})

// Cost-cover panel starts collapsed; it opens on its own once a signer is
// actually sponsored (e.g. a resumed draft) and otherwise stays under the
// visitor's manual toggle — it never auto-closes under them.
const costsPanelOpen = ref(false)
watch(signers, (list) => {
	if (list.some((s) => s.requesterSponsored)) { costsPanelOpen.value = true }
}, { immediate: true, deep: true })

// --- navigation ---
function goToView(view: View): void {
	activeView.value = view
}
function stepReachable(i: number): boolean {
	if (i === 0) { return true } // Email
	if (i === 1) { return requesterEmailValid.value } // Add signers
	// Setup positions is reachable only when signer changes are settled (saved,
	// not in flight or failed); Request additionally needs a placed field.
	if (i === 2) { return canEnterFields.value } // Setup positions
	return canEnterFields.value && hasPlacedFields.value // Request
}
// (prepareSubIndex dots were a chip-strip-only affordance — dropped with it;
// the numbered WorkflowStepper above doesn't sub-divide Prepare.)
function goToStep(i: number): void {
	if (!stepReachable(i)) { return }
	activeView.value = i >= 2 ? 'review' : i === 1 ? 'fields' : 'prepare'
}
function goToPrepareIdentify(): void { activeView.value = 'prepare'; prepareView.value = 'identify' }
function goToPrepareSigners(): void { activeView.value = 'prepare'; prepareView.value = 'signers' }

// Approach A: a single step label + a Back affordance live at the top of the
// panel; the footer carries only the forward move. `back` is null on the first
// screen (Upload), where there is nowhere to step back to.
// step text is unused now (the eyebrow is disabled — see pwn__stepcount — in
// favour of "Document action centre"), kept current so it's not stale if the
// eyebrow comes back.
const panelNav = computed<{ step: string, back: null | (() => void) }>(() => {
	if (activeView.value === 'fields') { return { step: t('libresign', 'Step 3 · Setup positions'), back: goToPrepareSigners } }
	if (activeView.value === 'review') { return { step: t('libresign', 'Step 4 · Review'), back: () => goToView('fields') } }
	if (prepareView.value === 'identify') { return { step: t('libresign', 'Step 1 · Email'), back: () => { prepareView.value = 'upload' } } }
	if (prepareView.value === 'signers') { return { step: t('libresign', 'Step 2 · Add signers'), back: () => { prepareView.value = 'identify' } } }
	return { step: t('libresign', 'Step 1 · Email'), back: null }
})

async function onStart(): Promise<void> {
	startedThisSession.value = true
	justResumed.value = false
	prepareView.value = 'upload'
	await start()
}

// Rebuild the signer set so the requester-signer tracks the current email and the
// self-sign intent. Single mutation → one non-empty saveSigners reconciles it
// (re-key = delete old email's sign-request, create the new one). Pure array work;
// the caller persists.
function reconcileRequesterSigner(oldEmail: string, newEmail: string, keep: boolean): void {
	const oldLc = oldEmail.trim().toLowerCase()
	const newLc = newEmail.trim().toLowerCase()
	if (oldLc !== '' && oldLc !== newLc) {
		const i = signers.value.findIndex((s) => s.email.trim().toLowerCase() === oldLc)
		if (i !== -1) { signers.value.splice(i, 1) }
	}
	const atNew = signers.value.findIndex((s) => s.email.trim().toLowerCase() === newLc)
	if (keep && newLc !== '') {
		if (atNew === -1) { signers.value.push({ displayName: '', email: newEmail.trim(), requesterSponsored: false, description: '' }) }
	} else if (atNew !== -1) {
		signers.value.splice(atNew, 1)
	}
}

// Auto-save the current signer set. Serialised: only ONE saveSigners is ever in
// flight, so a stale response can never reconcile over a newer edit (#2). If a
// change arrives mid-save, it is coalesced into one trailing save of the then
// current set. The shared DRAFT save is upsert-only and cannot clear to empty, so
// an empty set is a no-op; hydration from the response keeps the UI truthful.
// Local chips are preserved on failure (the store rethrows without mutating) and
// the error is retryable. Mutations are additionally blocked while saving (see the
// handler guards), so no edit is lost to the hydrate that lands mid-flight.
let saveInFlight = false
let saveQueued = false
async function persistSigners(): Promise<void> {
	if (saveInFlight) { saveQueued = true; return }
	saveInFlight = true
	signersSaving.value = true
	try {
		do {
			saveQueued = false
			signersError.value = ''
			const payload = buildSignerPayload(signers.value, { sponsorshipEnabled })
			if (payload.length === 0) { break }
			await saveSigners(payload)
		} while (saveQueued)
	} catch {
		signersError.value = errorMessage.value || t('libresign', 'Couldn’t save — your changes are kept. Try again.')
	} finally {
		saveInFlight = false
		signersSaving.value = false
	}
}

async function onIdentifyContinue(): Promise<void> {
	requesterTouched.value = true
	identifyError.value = ''
	if (!requesterEmailValid.value || savingRequester.value) { return }
	savingRequester.value = true
	try {
		const newEmail = requesterEmail.value.trim()
		// Re-key / materialise / release the requester-signer against the EXPLICIT
		// intent, using the tracked requester-signer email (never an email guess).
		reconcileRequesterSigner(requesterSignerEmail.value, newEmail, selfSign.value)
		await saveRequester(newEmail, selfSign.value)
		await persistSigners()
		// Record truth after the save: the requester is a signer iff intent held and
		// the row survived (the backend can refuse a 1→0 clear).
		const newLc = newEmail.toLowerCase()
		requesterSignerEmail.value = (selfSign.value && signers.value.some((s) => s.email.trim().toLowerCase() === newLc)) ? newEmail : ''
		selfSign.value = requesterSignerEmail.value !== ''
		prepareView.value = 'signers'
		// Save one dead click: a requester who is not self-signing lands on an empty
		// signer list whose sole action is "Add", so open that dialog once. A
		// self-signing requester already has a row (enteredSignerCount > 0), so it
		// stays closed. Fires at most once per session and only on this forward path.
		if (!signersAutoPrompted.value && enteredSignerCount.value === 0 && !signersSaving.value) {
			signersAutoPrompted.value = true
			openAdd(null)
		}
	} catch {
		identifyError.value = t('libresign', 'Could not save your email. Please try again.')
	} finally {
		savingRequester.value = false
	}
}

// --- upload ---
async function uploadFile(file: File): Promise<void> {
	uploadError.value = ''
	// Reject invalid/over-size files client-side before upload (shared rules).
	const validationError = validatePublicUploadFile(file)
	if (validationError) {
		uploadError.value = validationError
		return
	}
	uploading.value = true
	progress.value = 0
	try {
		await upload(file, (e) => {
			if (e.total) { progress.value = Math.round((e.loaded / e.total) * 100) }
		})
		if (hasDocument.value) { prepareView.value = 'identify' }
	} catch {
		uploadError.value = errorMessage.value || t('libresign', 'Upload failed')
	} finally {
		uploading.value = false
		if (fileInput.value) { fileInput.value.value = '' }
	}
}
async function onPick(ev: Event): Promise<void> {
	const file = (ev.target as HTMLInputElement).files?.[0]
	if (file) { await uploadFile(file) }
}
function onDrop(ev: DragEvent): void {
	dragOver.value = false
	const file = ev.dataTransfer?.files?.[0]
	if (!file) { return }
	if (file.type !== 'application/pdf') {
		uploadError.value = t('libresign', 'Please choose a PDF file.')
		return
	}
	void uploadFile(file)
}

// --- signers ---
// Mutations are blocked while a save is in flight so no add/edit/remove can be
// clobbered by the authoritative document hydrating mid-flight (#2).
function openAdd(index: number | null): void {
	if (signersSaving.value) { return }
	editIndex.value = index
	addOpen.value = true
}
// Add / edit auto-save: mutate the local set, then reconcile with the server. Edit
// re-keys (change an email) via the same non-empty reconcile — no manual Save.
async function onAddSigner(signer: { displayName: string, email: string, requesterSponsored: boolean, description: string }): Promise<void> {
	if (editIndex.value !== null && signers.value[editIndex.value]) {
		const existing = signers.value[editIndex.value]
		signers.value[editIndex.value] = { ...existing, displayName: signer.displayName, email: signer.email, requesterSponsored: signer.requesterSponsored, description: signer.description }
	} else {
		signers.value.push({ displayName: signer.displayName, email: signer.email, requesterSponsored: signer.requesterSponsored, description: signer.description })
	}
	editIndex.value = null
	await persistSigners()
}
async function removeSigner(index: number): Promise<void> {
	if (signersSaving.value) { return }
	signers.value.splice(index, 1)
	await persistSigners()
}

// --- editor ---
function openEditor(): void {
	void editor.value?.open()
}

// Keep the store binding explicit; `status` and reqEmailInput are intentionally referenced.
void status
void reqEmailInput
</script>

<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-shell {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	min-height: 0;
	height: 100%;
	container-type: inline-size;
	font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, blinkmacsystemfont, 'Segoe UI', roboto, sans-serif);
	color: var(--age-fg, var(--color-main-text));

	:deep(span.icon-vue) {
		min-width: 0;
		min-height: 0;
	}
}

/* states / hero */
.pw-shell__state {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 14px;
	padding: 48px 24px;
	text-align: center;
}

.pw-shell__state-ic {
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}

.pw-shell__state-title {
	margin: 0;
	font-family: var(--tenda-font-display, var(--tenda-font-family, inherit));
	font-size: 1.5rem;
	letter-spacing: -0.02em;
	color: var(--age-fg, var(--color-main-text));
}

.pw-muted {
	margin: 0;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	max-width: 52ch;
}

.pw-trust {
	margin: 0;
	font-size: 12px;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}

.pw-steps-preview {
	list-style: none;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 0;
	margin: 4px 0;
	font-size: 13px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pw-steps-preview__n {
	display: inline-grid;
	place-items: center;
	width: 20px;
	height: 20px;
	margin-inline-end: 6px;
	border-radius: 50%;
	background: var(--age-surface-2, var(--color-background-hover));
	font-family: var(--tenda-font-mono, monospace);
	font-size: 11px;
}

.pw-steps-preview__sep {
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}

/* ===== workspace grid ===== */
.pwn {
	flex: 1 1 auto;
	min-height: 0;
	display: flex;
}

.pwn__ws {
	position: relative;
	flex: 1 1 auto;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1.25fr) minmax(360px, 460px);
	grid-template-areas: "stage rail";
}

// Numbered stepper now lives at the top of the right panel (the authenticated
// composition), not floating over the stage — see pwn__rail-scroll below.
.pwn__stepper {
	flex: none;
	margin-bottom: 6px;
}

.pwn__peek-fab {
	display: none;
}

/* document stage: pre-upload empty state, or post-upload title + Documents
   subheader + card (pw-stagectl__bar is disabled — see the v-if="false" above). */
.pwn__stage {
	grid-area: stage;
	min-width: 0;
	min-height: 0;
	overflow: auto;
	display: flex;
	flex-direction: column;
	padding: 28px 32px;
	gap: 18px;
}

/* Center the content within the available stage width, excluding the rail. */
.pwn__stage-content {
	width: 100%;
	max-width: 640px;
	margin: 0 auto;
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.pwn__stage-empty {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 10px;
	text-align: center;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}

.pwn__stage-empty-title {
	margin: 4px 0 0;
	font-size: 15px;
	font-weight: 600;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pwn__stage-empty-sub {
	margin: 0;
	font-size: 13px;
	max-width: 32ch;
}

/* page title+subtext, mirrors Request.vue — shown only once a document exists,
   so it never duplicates the rail's own "Add your document" upload copy. */
.pwn__stage-head {
	text-align: center;
}

.pwn__stage-title {
	font-family: var(--tenda-font-display, var(--tenda-font-family, inherit));
	font-size: 26px;
	font-weight: 700;
	letter-spacing: -0.01em;
	margin: 0;
	color: var(--age-fg, var(--color-main-text));
}

.pwn__stage-sub {
	margin: 8px 0 0;
	font-size: 14px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

/* "Documents" subheader, mirrors DocumentList.vue */
.pwn__docs-head {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.pwn__docs-head-title {
	margin: 0;
	font-size: 13px;
	font-weight: 800;
	letter-spacing: 0.08em;
	color: var(--age-fg, var(--color-main-text));
}

.pwn__docs-head-count {
	font-size: 12px;
	font-weight: 700;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	background: var(--age-surface-2, var(--color-background-hover));
	border-radius: 999px;
	padding: 1px 7px;
}

.pwn__doctabs {
	position: relative;
	display: grid;
	grid-template-columns: 1fr 1fr;
	background: var(--age-surface-2, var(--color-background-hover));
	border-radius: var(--age-radius-sm, 8px);
	padding: 4px;
}

.pwn__doctabs-ind {
	position: absolute;
	top: 4px;
	bottom: 4px;
	left: 4px;
	width: calc(50% - 4px);
	background: var(--age-surface, var(--color-main-background));
	border-radius: calc(var(--age-radius-sm, 8px) - 1px);
	box-shadow: 0 1px 2px rgba(13, 17, 22, 0.08);
	transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);

	&.is-preview {
		transform: translateX(100%);
	}
}

.pwn__doctabs-tab {
	position: relative;
	z-index: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	border: none;
	background: none;
	padding: 9px 12px;
	font: inherit;
	font-size: 13px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	cursor: pointer;

	&:focus-visible {
		outline: 2px solid var(--age-focus, var(--color-primary-element));
		outline-offset: 2px;
		border-radius: calc(var(--age-radius-sm, 8px) - 1px);
	}

	&.is-active {
		color: var(--age-fg, var(--color-main-text));
		font-weight: 600;

		:deep(span.icon-vue) { color: var(--age-green-text, var(--color-success)); }
	}
}

.pwn__doctabs-pane {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

/* task rail */
.pwn__rail {
	grid-area: rail;
	border-inline-start: 1px solid var(--age-border, var(--color-border));
	background: var(--age-surface, var(--color-main-background));
	display: flex;
	flex-direction: column;
	min-height: 0;
}

.pwn__rail-scroll {
	overflow: auto;
	padding: 18px 26px 20px;
	display: flex;
	flex-direction: column;
	gap: 15px;
	flex: 1 1 auto;
}

/* panel-top nav: Back (left) + single step label (right) */
.pwn__pnav {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 4px;
	min-height: 30px;
}

.pwn__pnav-title {
	font-size: 20px;
	line-height: 30px;
	font-weight: 700;
	color: var(--age-fg, var(--color-main-text));
}

.pwn__back {
	display: inline-flex;
	align-items: center;
	gap: 6px;

	:deep(span.icon-vue) {
		display: inline-flex;
		transform: translateY(-0.5px);
	}
	border: none;
	background: none;
	font: inherit;
	font-size: 12.5px;
	font-weight: 600;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	cursor: pointer;
	padding: 5px 9px 5px 5px;
	border-radius: 7px;
}

.pwn__back:hover {
	background: var(--age-surface-2, var(--color-background-hover));
	color: var(--age-fg, var(--color-main-text));
}

.pwn__back:focus-visible {
	outline: 2px solid var(--age-focus, var(--color-primary-element));
	outline-offset: 1px;
}

.pwn__docinfo {
	display: flex;
	align-items: flex-start;
	gap: 12px;
}

.pwn__docinfo-icon {
	flex: none;
	width: 40px;
	height: 40px;
	border-radius: 11px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--age-green-text, var(--color-success));
	background: var(--age-green-dim, rgba(4, 213, 109, 0.12));
}

.pwn__docinfo-main {
	display: flex;
	flex-direction: column;
	gap: 5px;
	min-width: 0;
}

.pwn__docinfo-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	width: fit-content;
	padding: 3px 10px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	background: var(--age-hover, var(--color-background-hover));
	border: 1px solid var(--age-border, var(--color-border));
}

.pwn__docinfo-pill--purple {
	color: #5b5bd6;
	background: #ecebfb;
	border-color: rgba(91, 91, 214, 0.35);
}

.pwn__docinfo-pill--ready {
	color: var(--age-green-text, var(--color-success));
	background: var(--age-green-dim, rgba(4, 213, 109, 0.12));
	border-color: var(--age-green-dim, rgba(4, 213, 109, 0.3));
}

.pwn__docinfo-dot {
	width: 6px;
	height: 6px;
	border-radius: 999px;
	background: currentColor;
}

.pwn__docinfo-name {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	line-height: 1.2;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--age-fg, var(--color-main-text));
}

.pwn__docinfo-requester {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 0;
	font-size: 12px;
	font-weight: 600;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	opacity: 0.75;
}

.pwn__docinfo-change {
	border: none;
	background: none;
	font: inherit;
	font-size: 12px;
	font-weight: 600;
	color: var(--age-success-fg, #0a6636);
	cursor: pointer;
	opacity: 1;
	padding: 0;
}

.pwn__docinfo-divider {
	width: 100%;
	height: 1px;
	background: var(--age-border, var(--color-border));
	opacity: 0.7;
}

.pwn__stepcount {
	font-family: var(--tenda-font-mono, monospace);
	font-size: 10.5px;
	letter-spacing: 0.12em;
	text-transform: uppercase;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}

/* auto-save status (replaces the manual Save button) */
/* while a save is in flight the signer list is inert (mouse blocked here, and the
   handlers guard every path) so no edit races the authoritative hydrate */
.pwn__signers-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 2px;
}

.pwn__signers-head-label {
	font-size: 12px;
	font-weight: 800;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	opacity: 0.75;
}

.pwn__signers-head-add {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	border: none;
	border-radius: 7px;
	background: var(--age-green, var(--color-success));
	color: var(--age-green-contrast, #fff);
	font: inherit;
	font-size: 12.5px;
	font-weight: 600;
	padding: 6px 12px;
	cursor: pointer;
	transition: background 0.16s;
}

.pwn__signers-head-add:hover {
	background: var(--age-green-hover, var(--color-success-hover, var(--color-success)));
}

.pwn__signers-head-add:focus-visible {
	outline: 2px solid var(--age-focus, var(--color-primary-element));
	outline-offset: 2px;
}

.pwn__signers.is-busy {
	pointer-events: none;
	opacity: 0.6;
	transition: opacity 0.15s;
}

.pwn__autosave {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin: 2px 0 0;
	font-size: 12.5px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	min-height: 20px;
}

.pwn__autosave-ic {
	color: var(--age-success-fg, #0a6636);
}

.pwn__autosave :deep(span.icon-vue) {
	color: var(--age-success-fg, #0a6636);
}

.pwn__retry {
	border: none;
	background: none;
	font: inherit;
	font-size: 12.5px;
	font-weight: 600;
	color: var(--age-success-fg, #0a6636);
	cursor: pointer;
	padding: 0;
	text-decoration: underline;
}

.pwn__eyebrow {
	font-family: var(--tenda-font-mono, monospace);
	font-size: 10.5px;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	margin: 0;
}

.pwn__title {
	font-family: var(--tenda-font-display, var(--tenda-font-family, inherit));
	font-size: 23px;
	letter-spacing: -0.02em;
	margin: 2px 0 0;
	text-wrap: balance;
	color: var(--age-fg, var(--color-main-text));
}

.pwn__sub {
	font-size: 13.5px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
	max-width: 46ch;
}

.pwn__done {
	display: flex;
	gap: 9px;
	align-items: flex-start;
	font-size: 13px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
}

/* In-body step action (Save signers, Place/Edit signature fields). The footer
   holds navigation only; the step's own commit action lives here with its data. */
.pwn__action {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
	margin-top: 2px;
}

.pwn__saved {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12.5px;
	font-weight: 600;
	color: var(--age-success-fg, #0a6636);
}

.pwn__saved :deep(span.icon-vue) {
	color: var(--age-success-fg, #0a6636);
}

.pwn__done :deep(span.icon-vue) {
	color: var(--age-success-fg, #0a6636);
	flex: none;
}

/* In-flow forward CTA — a normal last item in .pwn__rail-scroll's own
   scrollable column, same as WorkflowActions is just the last card in the
   authenticated panel. No separate scroll box, so no second scrollbar. */
.pwn__cta {
	flex: none;
	margin-top: 4px;
	padding-top: 14px;
	border-top: 1px solid var(--age-border, var(--color-border));
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 8px;
}

.pwn__cta .pw-btn--primary {
	width: 100%;
}

.pwn__grow {
	margin-inline-start: auto;
}

/* mobile-only document-sheet chrome (hidden on desktop, where the stage is a pane) */
.pwn__scrim {
	display: none;
}

.pwn__grab {
	display: none;
}

.pwn__sheet-close {
	display: none;
}

/* reused leaf-content styles (ported from the current shell, token-themed) */
.pw-drop {
	border: 1.5px dashed var(--age-border-strong, var(--color-border-dark));
	border-radius: var(--age-radius, 14px);
	background: var(--age-surface-2, var(--color-background-hover));
	padding: 28px 20px;
	text-align: center;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	cursor: pointer;
	transition: border-color 0.2s, background 0.2s;
}

.pw-drop:hover,
.pw-drop.is-drag {
	border-color: var(--age-success, var(--color-primary-element));
	background: var(--age-success-tint, rgba(4, 213, 109, 0.12));
}

.pw-drop--busy {
	cursor: default;
}

.pw-drop__ic {
	width: 46px;
	height: 46px;
	border-radius: 13px;
	display: grid;
	place-items: center;
	background: var(--age-surface, var(--color-main-background));
	border: 1px solid var(--age-border, var(--color-border));
	color: var(--age-success-fg, #0a6636);
}

.pw-drop__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--age-fg, var(--color-main-text));
}

.pw-drop__sub {
	font-size: 12px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pw-drop__pill {
	font-family: var(--tenda-font-mono, monospace);
	font-size: 10.5px;
	letter-spacing: 0.06em;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	padding: 3px 9px;
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: 999px;
	background: var(--age-surface, var(--color-main-background));
}

.pw-progress {
	width: 100%;
	height: 6px;
	border-radius: 999px;
	background: var(--age-surface-3, var(--color-background-dark));
	overflow: hidden;
}

.pw-progress span {
	display: block;
	height: 100%;
	background: var(--age-success, var(--color-primary-element));
	transition: width 0.2s;
}

.pw-hidden-file {
	display: none;
}

.pw-emailfield {
	display: flex;
	flex-direction: column;
	gap: 7px;
}

.pw-emailfield__label {
	font-size: 12px;
	font-weight: 600;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pw-emailfield__input {
	width: 100%;
	border: 1px solid var(--age-border-strong, var(--color-border-dark));
	border-radius: var(--age-radius-sm, 9px);
	padding: 11px 13px;
	font: inherit;
	font-size: 14px;
	background: var(--age-surface, var(--color-main-background));
	color: var(--age-fg, var(--color-main-text));
}

.pw-emailfield__input:focus-visible {
	outline: none;
	border-color: var(--age-success, var(--color-primary-element));
	box-shadow: 0 0 0 3px var(--age-success-tint, rgba(4, 213, 109, 0.12));
}

.pw-emailfield__input.is-invalid {
	border-color: var(--color-error, #c0392b);
}

.pw-selfsign {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 14px;
	margin-top: 2px;
	border-top: 1px solid var(--age-border, var(--color-border));
}

.pw-selfsign__ctx {
	font-size: 13px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
}

.pw-selfsign__change {
	border: none;
	background: none;
	font: inherit;
	font-size: 12.5px;
	color: var(--age-success-fg, #0a6636);
	cursor: pointer;
	padding: 0 0 0 4px;
}

.pw-selfsign__check {
	display: inline-flex;
	align-items: center;
	gap: 9px;
	font-size: 13.5px;
	cursor: pointer;
}

.pw-selfsign__check input {
	width: 17px;
	height: 17px;
	accent-color: var(--age-success, var(--color-primary-element));
}

.pw-selfsign__note {
	font-size: 11.5px;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	margin: 0;
}

.pw-selfsign__pending {
	display: flex;
	align-items: center;
	gap: 7px;
	font-size: 12.5px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
}


.pw-help {
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: var(--age-radius-sm, 9px);
	background: var(--age-surface-2, var(--color-background-hover));
}

.pw-help__toggle {
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	border: none;
	background: none;
	font: inherit;
	font-size: 12.5px;
	color: var(--age-fg, var(--color-main-text));
	cursor: pointer;
	padding: 10px 14px;
	text-align: start;
}

.pw-help__toggle :deep(span.icon-vue) {
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	flex: none;
}

.pw-help.is-open .pw-help__toggle {
	padding-bottom: 2px;
}

.pw-help__copy {
	margin: 0;
	padding: 0 14px;
	font-size: 12.5px;
	line-height: 1.55;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pw-help__note {
	margin: 6px 0 0;
	padding: 0 14px 12px;
	font-size: 11.5px;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}


.pw-fieldstat {
	display: flex;
	align-items: center;
	gap: 9px;
	font-size: 13px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
}

.pw-fieldstat :deep(span.icon-vue) {
	color: var(--age-success-fg, #0a6636);
	flex: none;
}

.pw-summary {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 9px;
}

.pw-sumrow {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 13px;
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: var(--age-radius-sm, 9px);
}

.pw-sumrow :deep(span.icon-vue) {
	color: var(--age-success-fg, #0a6636);
	flex: none;
}

.pw-sumrow__text {
	min-width: 0;
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
}

.pw-sumrow__k {
	font-size: 13.5px;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--age-fg, var(--color-main-text));
}

.pw-sumrow__v {
	font-size: 12px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pw-sumrow__edit {
	border: none;
	background: none;
	font: inherit;
	font-size: 12.5px;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	cursor: pointer;
	flex: none;
}

.pw-sumrow__edit:hover {
	color: var(--age-success-fg, #0a6636);
}

.pw-notsent {
	margin: 0;
	padding: 13px;
	border-radius: var(--age-radius-sm, 9px);
	border: 1px dashed var(--age-success-border, rgba(4, 213, 109, 0.34));
	background: var(--age-success-tint, rgba(4, 213, 109, 0.12));
	font-size: 12.5px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
}

.pw-notsent strong {
	color: var(--age-fg, var(--color-main-text));
}

.pw-claim {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding-top: 4px;
}

.pw-claim__recap {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	font-size: 13px;
}

.pw-claim__recap-k {
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
}


.pw-claim__recap-missing {
	color: var(--color-error, #c0392b);
}

.pw-savenote {
	font-size: 11.5px;
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	margin: 0;
}

.pw-err {
	font-size: 12.5px;
	color: var(--color-error, #c0392b);
	margin: 0;
}

/* buttons */
.pw-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	border: 1px solid var(--age-border-strong, var(--color-border-dark));
	background: var(--age-surface, var(--color-main-background));
	color: var(--age-fg, var(--color-main-text));
	font: inherit;
	font-weight: 600;
	font-size: 14px;
	padding: 11px 18px;
	border-radius: var(--age-radius-sm, 9px);
	cursor: pointer;
	transition: background 0.18s, transform 0.1s;
}

.pw-btn:hover {
	background: var(--age-surface-2, var(--color-background-hover));
}

.pw-btn:active {
	transform: translateY(1px);
}

.pw-btn--primary {
	background: var(--age-success, var(--color-primary-element));
	border-color: transparent;
	color: var(--age-on-success, var(--color-primary-element-text));
}

.pw-btn--primary:hover {
	background: var(--age-success-strong, var(--color-primary-element-hover));
}

.pw-btn--ghost {
	border-color: transparent;
	background: none;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	font-weight: 500;
}

.pw-btn--ghost:hover {
	color: var(--age-fg, var(--color-main-text));
	background: var(--age-surface-2, var(--color-background-hover));
}

.pw-btn[disabled] {
	opacity: 0.45;
	cursor: not-allowed;
}

/* ===== mobile: scrollable strip + peek doc + stacked task ===== */
@container (max-width: 860px) {

	/* Stacked flex column: every level needs min-width:0 so it can shrink to the
	   viewport instead of overflowing to its content's min-content width. */
	.pwn__ws {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	/* Fixed above the CTA within .pw-shell; hidden while the document sheet is open. */
	.pwn__peek-fab {
		display: flex;
		align-items: center;
		justify-content: center;
		position: fixed;
		z-index: 46;
		right: 18px;
		bottom: calc(100px + env(safe-area-inset-bottom, 0px));
		width: 52px;
		height: 52px;
		border-radius: 50%;
		border: none;
		background: var(--age-success, var(--color-primary-element));
		color: var(--age-on-success, var(--color-primary-element-text));
		box-shadow: 0 10px 26px rgba(4, 213, 109, 0.32), 0 2px 8px rgba(13, 17, 22, 0.12);
		cursor: pointer;
	}

	.pwn__rail {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 0;
		border-inline-start: none;
		border-top: 1px solid var(--age-border, var(--color-border));
	}

	// Shared clearance for the fixed CTA and the rail's bottom scroll padding.
	$pwn-cta-clearance: 86px;

	.pwn__rail-scroll {
		min-width: 0;
		// Keep the final scrollable content clear of the fixed CTA.
		padding-bottom: calc(#{$pwn-cta-clearance} + env(safe-area-inset-bottom, 0px));
	}

	/* Fixed positioning keeps the CTA visible outside the rail's scroll flow.
	   .pw-shell provides its containing block through container-type: inline-size. */
	.pwn__cta {
		position: fixed;
		z-index: 45;
		inset-inline: 0;
		bottom: 0;
		margin: 0;
		padding: 14px 26px calc(14px + env(safe-area-inset-bottom, 0px));
		background: var(--age-surface, var(--color-main-background));
		box-shadow: 0 -8px 24px -12px rgba(13, 17, 22, 0.18);
	}

	/* Stage padding is tighter on mobile, and clears the grab handle/close button
	   (hidden on desktop) pinned to the top of the sheet. */
	.pwn__stage {
		padding: 44px 20px 24px;
		gap: 14px;
	}

	.pwn__stage-title {
		font-size: 21px;
	}

	/* Document stage becomes a single-height, opt-in bottom-sheet (closed by
	   default). Rendered off-screen so the PDF + overlays are ready on first open. */
	.pwn__stage {
		position: fixed;
		inset-inline: 0;
		bottom: 0;
		z-index: 60;
		height: 88vh;
		max-height: 88vh;
		background: var(--age-surface, var(--color-main-background));
		border-radius: 18px 18px 0 0;
		border-top: 1px solid var(--age-border, var(--color-border));
		box-shadow: 0 -24px 60px -30px rgba(0, 0, 0, 0.5);
		transform: translateY(100%);
		transition: transform 0.3s cubic-bezier(0.2, 0.7, 0.3, 1);
	}

	.pwn:not(.is-doc-hidden) .pwn__stage {
		transform: none;
	}

	.pwn__scrim {
		display: block;
		position: fixed;
		inset: 0;
		z-index: 55;
		background: rgba(0, 0, 0, 0.42);
		opacity: 0;
		pointer-events: none;
		transition: opacity 0.24s;
	}

	.pwn:not(.is-doc-hidden) .pwn__scrim {
		opacity: 1;
		pointer-events: auto;
	}

	.pwn__grab {
		display: block;
		position: absolute;
		top: 7px;
		inset-inline-start: 50%;
		transform: translateX(-50%);
		z-index: 2;
		width: 40px;
		height: 4px;
		border-radius: 999px;
		background: var(--age-border-strong, var(--color-border-dark));
	}

	.pwn__sheet-close {
		display: grid;
		place-items: center;
		position: absolute;
		top: 12px;
		inset-inline-end: 14px;
		z-index: 2;
		width: 34px;
		height: 34px;
		border: 1px solid var(--age-border, var(--color-border));
		border-radius: 50%;
		background: var(--age-surface, var(--color-main-background));
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
		cursor: pointer;
	}

	.pwn__sheet-close:focus-visible {
		outline: 2px solid var(--age-focus, var(--color-primary-element));
		outline-offset: 2px;
	}
}

@media (prefers-reduced-motion: reduce) {
	* {
		animation-duration: 0.001ms !important;
		transition-duration: 0.001ms !important;
	}
}
</style>
