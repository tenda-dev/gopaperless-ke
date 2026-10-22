<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Concept B — the public (anonymous) document-preparation workspace.

  A single cohesive panel (focused column, max 980px) under a slim progress
  ribbon. The ribbon drives a view-switched body — Prepare (document + signers),
  Fields (opens the canvas editor), Review — so each stage shows only its own
  content and its own primary action; no stacked, always-on cards. Guidance
  appears contextually. Draft preparation ONLY: no send, account handoff,
  payments, credits, or signing.

  The GoPaperless header + ecosystem footer are the shared public chrome
  (PublicShell), the same as /p/upload; the Nextcloud public header/footer are
  dropped by the global `#body-public:has(.pu)` rule. Body palette: age-tokens.
  public editor are the only data paths.
-->
<template>
	<PublicShell>
		<div class="pw-shell">
			<!-- loading -->
			<div v-if="phase === 'loading'" class="pw-shell__state" aria-live="polite">
				<NcLoadingIcon :size="40" :name="t('libresign', 'Loading…')" />
			</div>

			<!-- expired / invalid -->
			<div v-else-if="phase === 'expired'" class="pw-shell__state">
				<NcIconSvgWrapper class="pw-shell__state-ic" :path="mdiClockAlertOutline" :size="40" />
				<h2 class="pw-shell__state-title">{{ t('libresign', 'This draft has expired') }}</h2>
				<p class="pw-muted">{{ t('libresign', 'Drafts are kept for 72 hours. This one is no longer available — you can start a fresh one.') }}</p>
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

			<!-- ready -->
			<div v-else-if="phase === 'ready'" class="pw-shell__panel">
				<nav class="pw-chips" :aria-label="t('libresign', 'Progress')">
					<template v-for="(label, i) in stepLabels" :key="i">
						<button
							type="button"
							class="pw-chip"
							:class="{ 'is-done': i < activeStepIndex && stepReachable(i), 'is-current': i === activeStepIndex }"
							:aria-current="i === activeStepIndex ? 'step' : undefined"
							:disabled="!stepReachable(i)"
							@click="goToStep(i)">
							<span class="pw-chip__dot">
								<NcIconSvgWrapper v-if="i < activeStepIndex && stepReachable(i)" :path="mdiCheck" :size="13" />
								<template v-else>{{ i + 1 }}</template>
							</span>
							<span class="pw-chip__label">{{ label }}</span>
							<span v-if="i === 0 && activeStepIndex === 0" class="pw-chip__sub" aria-hidden="true">
								<i v-for="n in 3" :key="n" :class="{ 'is-on': n - 1 < prepareSubIndex, 'is-cur': n - 1 === prepareSubIndex }" />
							</span>
						</button>
						<span v-if="i < stepLabels.length - 1" class="pw-chips__sep" aria-hidden="true" />
					</template>
				</nav>

				<main class="pw-shell__body">
					<!-- PREPARE: upload → identify (requester email) → signers.
					     All three are the single ribbon step "Prepare"; identify is an
					     intermediate view, NOT a fourth progress stage. -->
					<template v-if="activeView === 'prepare'">
						<PwReassurance v-if="prepareView !== 'upload'" />

						<p v-if="justResumed" class="pw-resume" role="status">
							<NcIconSvgWrapper :path="mdiClockOutline" :size="16" />
							<span>{{ expiresLabel ? t('libresign', 'We kept your draft. {e}.', { e: expiresLabel }) : t('libresign', 'We kept your draft.') }}</span>
						</p>

						<!-- UPLOAD -->
						<template v-if="prepareView === 'upload'">
							<div v-if="!hasDocument" class="pw-up">
								<div class="pw-up__scroll">
									<div class="pw-stage">
										<p class="pw-prepstep">{{ t('libresign', 'Prepare · Step 1 of 3') }}</p>
										<h2 class="pw-stage__title">{{ t('libresign', 'Add your document') }}</h2>
										<p class="pw-stage__sub">{{ t('libresign', 'Upload the PDF you need signed. It becomes a private draft — nothing is sent or signed yet.') }}</p>
										<PwReassurance />
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
											<span class="pw-drop__sub">{{ t('libresign', 'Kept privately for 72 hours.') }}</span>
											<span class="pw-drop__pill">{{ t('libresign', 'PDF · 25 MB max') }}</span>
										</div>
										<div v-else class="pw-drop pw-drop--busy" aria-live="polite">
											<NcLoadingIcon :size="32" :name="t('libresign', 'Uploading…')" />
											<span class="pw-drop__title">{{ t('libresign', 'Uploading…') }}</span>
											<div class="pw-progress"><span :style="{ width: progress + '%' }" /></div>
										</div>
										<input ref="fileInput" type="file" accept="application/pdf" class="pw-hidden-file" @change="onPick">
										<p v-if="uploadError" class="pw-err" role="alert">{{ uploadError }}</p>
									</div>
								</div>
								<div class="pw-stage-foot">
									<div class="pw-stage-foot__in">
										<p class="pw-savenote">{{ t('libresign', 'Step 1 of 3 · Prepare') }}</p>
										<button type="button" class="pw-btn pw-btn--primary pw-stage-foot__cta" :disabled="!hasDocument" @click="prepareView = 'identify'">
											{{ t('libresign', 'Continue') }} →
										</button>
									</div>
								</div>
							</div>
							<template v-else>
								<PwDocumentCard :name="documentName"
									:pages="documentPages"
									:expiry="expiresLabel"
									:signer-count="enteredSignerCount"
									@replace="onReplace"
						@view="previewOpen = true" />
								<p v-if="uploadError" class="pw-err">{{ uploadError }}</p>
								<div class="pw-card__actions">
									<button type="button" class="pw-btn pw-btn--primary pw-card__next" @click="prepareView = 'identify'">{{ t('libresign', 'Continue') }} →</button>
								</div>
							</template>
						</template>

						<!-- IDENTIFY: requester email (contact only; not a signer, not an account) -->
						<template v-else-if="prepareView === 'identify'">
							<PwDocumentCard :name="documentName"
								:pages="documentPages"
								:expiry="expiresLabel"
								:signer-count="enteredSignerCount"
								@replace="onReplace"
						@view="previewOpen = true" />
							<section class="pw-card">
								<p class="pw-prepstep">{{ t('libresign', 'Step 2 of preparation') }}</p>
								<h2 class="pw-card__title">{{ t('libresign', 'What’s your email address?') }}</h2>
								<p class="pw-muted">{{ t('libresign', 'Tell us which email address you’re using to prepare this document. You can easily add yourself as a signer in the next step.') }}</p>
								<div class="pw-emailfield">
									<label class="pw-emailfield__label" for="pw-req-email">{{ t('libresign', 'Your email') }}</label>
									<input id="pw-req-email"
										ref="reqEmailInput"
										v-model.trim="requesterEmail"
										type="email"
										class="pw-emailfield__input"
										:class="{ 'is-invalid': requesterEmailShowError }"
										autocomplete="email"
										inputmode="email"
										:aria-invalid="requesterEmailShowError || undefined"
										aria-describedby="pw-req-note pw-req-err"
										:placeholder="t('libresign', 'name@example.com')"
										@blur="requesterTouched = true"
										@keyup.enter="onIdentifyContinue">
									<p v-if="requesterEmailShowError" id="pw-req-err" class="pw-err">{{ t('libresign', 'Enter a valid email address, like name@example.com.') }}</p>
									<p id="pw-req-note" class="pw-selfsign__note">{{ t('libresign', 'This is your contact email for preparing the draft. Entering it doesn’t verify the address, create an account, or send anything.') }}</p>
								</div>
								<div class="pw-card__actions">
									<button type="button" class="pw-btn pw-btn--ghost" @click="prepareView = 'upload'">← {{ t('libresign', 'Back') }}</button>
									<span v-if="identifyError" class="pw-err">{{ identifyError }}</span>
									<button type="button"
										class="pw-btn pw-btn--primary pw-card__next"
										:disabled="savingRequester || !requesterEmailValid"
										@click="onIdentifyContinue">
										{{ savingRequester ? t('libresign', 'Saving…') : t('libresign', 'Continue') }}
									</button>
								</div>
							</section>
						</template>

						<!-- SIGNERS -->
						<template v-else>
							<PwDocumentCard :name="documentName"
								:pages="documentPages"
								:expiry="expiresLabel"
								:signer-count="enteredSignerCount"
								@replace="onReplace"
						@view="previewOpen = true" />

							<section class="pw-card">
								<div class="pw-card__head">
									<h2 class="pw-card__title">{{ t('libresign', 'Who needs to sign?') }}</h2>
								</div>
								<p class="pw-muted">{{ t('libresign', 'Signers are the people who will sign this document. You’re preparing it as {email} — your contact email, kept separate from the signers below.', { email: requesterEmail || t('libresign', 'your email') }) }}</p>
								<PwContextualHint v-if="enteredSignerCount === 0">
									{{ t('libresign', 'Add the people who will sign. We only use these details when you choose to send — nothing is sent now.') }}
								</PwContextualHint>
								<PwSignerList :signers="signers" :requester-email="requesterIsSigner ? requesterEmail : ''" @add="openAdd(null)" @edit="openAdd($event)" @remove="removeSigner($event)" />
								<div class="pw-selfsign">
									<p class="pw-selfsign__ctx">
										{{ t('libresign', 'Preparing as') }} <strong>{{ requesterEmail || t('libresign', 'your email') }}</strong>.
										<button type="button" class="pw-selfsign__change" @click="prepareView = 'identify'">{{ t('libresign', 'Change') }}</button>
									</p>
									<label class="pw-selfsign__check">
										<input v-model="requesterIsSigner" type="checkbox">
										<span>{{ t('libresign', 'I will also sign this document') }}</span>
									</label>
									<p class="pw-selfsign__note">{{ t('libresign', 'Optional. Tick this to add yourself as a signer using the email above — you won’t need to retype it. Leave it off to request signatures from others only.') }}</p>
									<p v-if="requesterPending" class="pw-selfsign__pending">
										<NcIconSvgWrapper :path="mdiAccountPlusOutline" :size="15" />
										<span>{{ t('libresign', 'You’ll be added as a signer ({email}) when you save.', { email: requesterEmail }) }}</span>
									</p>
								</div>
								<div v-if="sponsorshipEnabled" class="pw-help">
									<button type="button"
										class="pw-help__toggle"
										:aria-expanded="helpOpen"
										aria-controls="pw-help-panel"
										@click="helpOpen = !helpOpen">
										<NcIconSvgWrapper :path="mdiHelpCircleOutline" :size="16" />
										<span>{{ t('libresign', 'How does sponsorship work?') }}</span>
										<NcIconSvgWrapper class="pw-help__chev" :class="{ 'pw-help__chev--open': helpOpen }" :path="mdiChevronDown" :size="18" />
									</button>
									<div v-show="helpOpen" id="pw-help-panel" class="pw-help__panel" role="region" :aria-label="t('libresign', 'How does sponsorship work?')">
										<p class="pw-help__h">{{ t('libresign', 'What does sponsoring a signer mean?') }}</p>
										<p>{{ t('libresign', 'When you sponsor a signer, you cover their signing cost using your eligible certified signatures, rather than requiring them to pay for it themselves.') }}</p>
										<p>{{ t('libresign', 'You choose which signers to sponsor. Adding signers and preparing this draft does not send invitations or sign the document.') }}</p>
									</div>
								</div>
								<div class="pw-card__actions">
									<button type="button"
										class="pw-btn pw-btn--primary"
										:disabled="savingSigners || !hasEnteredSigner"
										@click="onSaveSigners">
										{{ savingSigners ? t('libresign', 'Saving…') : t('libresign', 'Save signers') }}
									</button>
									<span v-if="prepError" class="pw-err">{{ prepError }}</span>
									<span v-else class="pw-savenote">{{ t('libresign', 'Saved as a draft · nothing is sent') }}</span>
									<button v-if="hasSavedSigners"
										type="button"
										class="pw-btn pw-btn--ghost pw-card__next"
										@click="goToView('fields')">
										{{ t('libresign', 'Continue to fields') }} →
									</button>
								</div>
							</section>
						</template>
					</template>

					<!-- FIELDS: launches the canvas editor -->
					<section v-else-if="activeView === 'fields'" class="pw-card">
						<h2 class="pw-card__title">{{ t('libresign', 'Signature fields') }}</h2>
						<p class="pw-muted">{{ t('libresign', 'Place where each person signs. Tap a signer, then tap the page.') }}</p>

						<PwContextualHint v-if="!hasSavedSigners">
							{{ t('libresign', 'Save at least one signer before placing fields.') }}
						</PwContextualHint>
						<p v-else class="pw-fieldstat">
							<NcIconSvgWrapper :path="hasPlacedFields ? mdiCheckCircleOutline : mdiMapMarkerOutline" :size="16" />
							<span>{{ hasPlacedFields
								? t('libresign', '{n} field(s) placed across {s} signer(s)', { n: visibleElements.length, s: enteredSignerCount })
								: t('libresign', 'No fields placed yet.') }}</span>
						</p>

						<div class="pw-card__actions">
							<button type="button" class="pw-btn pw-btn--ghost" @click="goToView('prepare')">← {{ t('libresign', 'Back') }}</button>
							<button type="button" class="pw-btn pw-btn--primary" :disabled="!hasSavedSigners" @click="openEditor">
								<NcIconSvgWrapper :path="mdiFormatColorFill" :size="18" />
								{{ hasPlacedFields ? t('libresign', 'Edit signature positions') : t('libresign', 'Place signature fields') }}
							</button>
							<button v-if="hasPlacedFields"
								type="button"
								class="pw-btn pw-btn--ghost pw-card__next"
								@click="goToView('review')">
								{{ t('libresign', 'Review') }} →
							</button>
						</div>
					</section>

					<!-- REVIEW: restrained draft summary (no send, no account, no padlock) -->
					<template v-else>
						<PwReassurance />
						<section class="pw-card">
							<h2 class="pw-card__title">{{ t('libresign', 'Review your draft') }}</h2>
							<ul class="pw-summary">
								<li class="pw-sumrow">
									<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="18" />
									<span class="pw-sumrow__text">
										<span class="pw-sumrow__k">{{ documentName }}</span>
										<span class="pw-sumrow__v">{{ documentPages > 0 ? t('libresign', '{n} page(s) · Draft', { n: documentPages }) : t('libresign', 'Draft') }}</span>
									</span>
									<button type="button" class="pw-sumrow__edit" @click="goToPrepareUpload">{{ t('libresign', 'Change') }}</button>
								</li>
								<li class="pw-sumrow">
									<NcIconSvgWrapper :path="mdiAccountGroupOutline" :size="18" />
									<span class="pw-sumrow__text">
										<span class="pw-sumrow__k">{{ t('libresign', '{n} signer(s)', { n: enteredSignerCount }) }}</span>
										<span v-if="signerNames" class="pw-sumrow__v">{{ signerNames }}</span>
									</span>
									<button type="button" class="pw-sumrow__edit" @click="goToPrepareSigners">{{ t('libresign', 'Edit') }}</button>
								</li>
								<li class="pw-sumrow">
									<NcIconSvgWrapper :path="mdiFormatColorFill" :size="18" />
									<span class="pw-sumrow__text">
										<span class="pw-sumrow__k">{{ t('libresign', '{n} signature field(s) placed', { n: visibleElements.length }) }}</span>
									</span>
									<button type="button" class="pw-sumrow__edit" @click="goToView('fields')">{{ t('libresign', 'Adjust') }}</button>
								</li>
							</ul>
							<button type="button" class="pw-btn pw-btn--ghost pw-viewdoc" @click="previewOpen = true">
								<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="16" />
								{{ t('libresign', 'View document') }}
							</button>
							<p class="pw-notsent">
								<strong>{{ t('libresign', 'Nothing has been sent, signed, or emailed.') }}</strong>
								{{ t('libresign', 'Your draft is saved and available for 72 hours — you can keep editing or come back later.') }}
							</p>

							<div class="pw-claim">
								<div class="pw-claim__recap">
									<span class="pw-claim__recap-k">{{ t('libresign', 'Sending as') }}</span>
									<span v-if="requesterEmailValid" class="pw-claim__recap-v">
										{{ requesterEmail }}<template v-if="requesterIsSigner"> · {{ t('libresign', 'you will also sign') }}</template>
									</span>
									<span v-else class="pw-claim__recap-missing">{{ t('libresign', 'Add your email to continue') }}</span>
									<button type="button" class="pw-sumrow__edit" @click="goToPrepareIdentify">{{ requesterEmailValid ? t('libresign', 'Edit') : t('libresign', 'Add your email') }}</button>
								</div>
								<button type="button"
									class="pw-btn pw-btn--primary pw-claim__cta"
									:disabled="signingIn || !requesterEmailValid"
									@click="signInToSend">
									{{ signingIn ? t('libresign', 'Redirecting…') : t('libresign', 'Sign in to continue') }}
								</button>
								<p v-if="claimError" class="pw-err">{{ claimError }}</p>
								<p class="pw-savenote">{{ t('libresign', 'You’ll sign in to finish. Your signature requests are sent only after you confirm on the next step.') }}</p>
							</div>
						</section>
					</template>
				</main>
			</div>

			<PwDocumentPreview :open="previewOpen" :name="documentName" :pages="documentPages" @update:open="previewOpen = $event" />

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
	mdiAccountPlusOutline,
	mdiCheck,
	mdiCheckCircleOutline,
	mdiChevronDown,
	mdiClockAlertOutline,
	mdiClockOutline,
	mdiFileDocumentOutline,
	mdiFormatColorFill,
	mdiHelpCircleOutline,
	mdiMapMarkerOutline,
	mdiTrayArrowUp,
} from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PwAddSignerDialog from './PwAddSignerDialog.vue'
import PwContextualHint from './PwContextualHint.vue'
import PwDocumentCard from './PwDocumentCard.vue'
import PwDocumentPreview from './PwDocumentPreview.vue'
import PwReassurance from './PwReassurance.vue'
import PwSignerList from './PwSignerList.vue'
import PublicVisibleElements from './PublicVisibleElements.vue'
import PublicShell from '../PublicShell.vue'
import { usePublicWorkflow, type SignerDraft } from '../../store/publicWorkflow'
import { buildSignerPayload, isPlausibleEmail, withRequesterSigner } from './signerPayload'

defineOptions({ name: 'PublicWorkflowShell' })

// Capability gate mirrors IdentifySigner.vue; the flag is provided to the
// public page via PageController::publicWorkflow() -> AccountService::getConfig().
const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as { sponsorship_enabled?: boolean }
).sponsorship_enabled ?? false

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
const savingSigners = ref(false)
const prepError = ref('')
const addOpen = ref(false)
const previewOpen = ref(false)
const dragOver = ref(false)
const editIndex = ref<number | null>(null)
const editor = ref<InstanceType<typeof PublicVisibleElements> | null>(null)
const activeView = ref<View>('prepare')
// Prepare is a single ribbon step with three intermediate views, in order.
const prepareView = ref<'upload' | 'identify' | 'signers'>('upload')
const reqEmailInput = ref<HTMLInputElement | null>(null)
const savingRequester = ref(false)
const identifyError = ref('')
const requesterTouched = ref(false)
const justResumed = ref(false)
const startedThisSession = ref(false)
const helpOpen = ref(false)
const requesterEmail = ref('')
const requesterIsSigner = ref(false)
const signingIn = ref(false)
const claimError = ref('')
// The public OIDC "sign in" URL already redirects to the authenticated /f/request
// landing, where the claim prompt detects this browser's capability cookie.
const oidcLoginUrl = (loadState('libresign', 'public_upload_oidc_login_url', '') as string) || ''
async function signInToSend(): Promise<void> {
	claimError.value = ''
	signingIn.value = true
	try {
		// DRAFT-only: record the requester's email + self-signer intent so the
		// claim can match/materialise after login. Nothing is sent or charged.
		await saveRequester(requesterEmail.value.trim() || null, requesterIsSigner.value)
		const fallback = generateUrl('/login') + '?redirect_url=' + encodeURIComponent(generateUrl('/apps/libresign/f/request'))
		window.location.href = oidcLoginUrl || fallback
	} catch {
		claimError.value = t('libresign', 'Could not save your details. Please try again.')
		signingIn.value = false
	}
}

// Seed the editable requester inputs from the server-rehydrated intent (resume).
// Guarded so it only runs when the store actually carries a value, never
// clobbering a value the viewer is mid-typing with an empty default.
watch(storeRequesterEmail, (v) => { if (v != null && v !== '') { requesterEmail.value = v } }, { immediate: true })
watch(storeRequesterIsSigner, (v) => { if (v) { requesterIsSigner.value = true } }, { immediate: true })

// Toggling "I will also sign" OFF must remove the requester's signer row so the
// next save reconciles it away on the server; ON is folded in at save time via
// withRequesterSigner (and shown as a pending hint until saved).
watch(requesterIsSigner, (on) => {
	if (on) { return }
	const email = requesterEmail.value.trim().toLowerCase()
	if (email === '') { return }
	const i = signers.value.findIndex((srow) => srow.email.trim().toLowerCase() === email)
	if (i !== -1) { signers.value.splice(i, 1) }
})

// Resume first, then land on the right Prepare sub-view: a restored draft with a
// document but no requester email goes to Identify; with an email, straight to
// Signers. A fresh (non-resumed) session starts at Upload. requesterEmail is read
// from the store (authoritative) rather than the local ref, which the watch above
// may not have copied yet this tick.
onMounted(async () => {
	await resume()
	if (phase.value === 'ready' && hasDocument.value) {
		justResumed.value = !startedThisSession.value
		prepareView.value = storeRequesterEmail.value ? 'signers' : 'identify'
	} else {
		prepareView.value = 'upload'
	}
})

// Never strand the viewer on a stage that no longer applies: without a document
// there is nothing to place or review, so fall back to Prepare/Upload.
watch(hasDocument, (has) => {
	if (!has) { activeView.value = 'prepare'; prepareView.value = 'upload' }
})

// --- derived ---
const documentName = computed(() => (doc.value as { name?: string } | null)?.name || t('libresign', 'Your document'))
const documentPages = computed(() => Number((doc.value as { metadata?: { p?: number } } | null)?.metadata?.p ?? 0))
const hasEnteredSigner = computed(() => signers.value.some((s) => s.email.trim()))
// Requester email gate for the Review CTA: a minimal, forgiving email shape
// (something@something.tld). Server-side validation remains authoritative.
const requesterEmailValid = computed(() => isPlausibleEmail(requesterEmail.value))
// Inline error shows only once the field has content and has been touched, so an
// untouched/empty field is never pre-flagged (Continue is disabled regardless).
const requesterEmailShowError = computed(() => requesterTouched.value && requesterEmail.value.trim().length > 0 && !requesterEmailValid.value)
// Self-signing requested but the requester is not yet a saved signer row — show
// a one-line pending hint rather than a second chip (the badged list row covers
// the saved case, deduping against a manually added matching email).
const requesterPending = computed(() => {
	if (!requesterIsSigner.value) { return false }
	const email = requesterEmail.value.trim().toLowerCase()
	if (email === '') { return false }
	return !signers.value.some((s) => s.email.trim().toLowerCase() === email)
})
const hasSavedSigners = computed(() => signers.value.some((s) => s.signRequestId != null))
const hasPlacedFields = computed(() => visibleElements.value.length > 0)
const enteredSignerCount = computed(() => signers.value.filter((s) => s.email.trim()).length)
const signerNames = computed(() => signers.value
	.filter((s) => s.email.trim())
	.map((s) => s.displayName.trim() || s.email.trim())
	.join(' · '))
const editRow = computed<SignerDraft | null>(() => (editIndex.value !== null ? signers.value[editIndex.value] ?? null : null))
const otherSigners = computed(() => signers.value.filter((_, i) => i !== editIndex.value && signers.value[i].email.trim()))

// Approved three-step model. Document upload, requester-email (Identify) and
// signer configuration all live within "Prepare".
const stepLabels = computed(() => [
	t('libresign', 'Prepare'),
	t('libresign', 'Fields'),
	t('libresign', 'Review'),
])
// Progress (completion) — drives the done checkmarks. Prepare is complete once
// signers are saved (ready to place fields).
const currentStep = computed(() => {
	if (!hasSavedSigners.value) { return 0 }
	if (!hasPlacedFields.value) { return 1 }
	return 2
})
// Which ribbon step the current view maps to — drives the highlight.
const activeStepIndex = computed(() => {
	if (activeView.value === 'fields') { return 1 }
	if (activeView.value === 'review') { return 2 }
	return 0
})

const expiresLabel = computed(() => {
	if (!expiresAt.value) { return '' }
	const ms = new Date(expiresAt.value).getTime() - Date.now()
	if (!Number.isFinite(ms) || ms <= 0) { return '' }
	const hours = Math.round(ms / 3_600_000)
	return t('libresign', 'Expires in {h}h', { h: hours })
})

// --- navigation ---
function goToView(view: View): void {
	activeView.value = view
}
function stepReachable(i: number): boolean {
	// Chips never allow skipping into an incomplete stage: Fields needs saved
	// signers, Review needs placed fields. Going back is always allowed.
	if (i === 0) { return true }
	if (i === 1) { return hasSavedSigners.value }
	return hasSavedSigners.value && hasPlacedFields.value
}
// Sub-step position within Prepare, for the active chip's progress dots.
const prepareSubIndex = computed(() => ({ upload: 0, identify: 1, signers: 2 })[prepareView.value] ?? 0)
function goToStep(i: number): void {
	if (!stepReachable(i)) { return }
	activeView.value = i >= 2 ? 'review' : i === 1 ? 'fields' : 'prepare'
}
// Prepare sub-view jumps used by the Review summary's edit affordances.
function goToPrepareUpload(): void { activeView.value = 'prepare'; prepareView.value = 'upload' }
function goToPrepareIdentify(): void { activeView.value = 'prepare'; prepareView.value = 'identify' }
function goToPrepareSigners(): void { activeView.value = 'prepare'; prepareView.value = 'signers' }

// Start a fresh draft (distinguished from a cookie resume so the "we kept your
// draft" line never shows for a brand-new session).
async function onStart(): Promise<void> {
	startedThisSession.value = true
	justResumed.value = false
	prepareView.value = 'upload'
	await start()
}

// Identify step → persist the requester email as DRAFT contact info and advance
// to signers. isSigner is intentionally false here: self-signing is a separate,
// opt-in decision made on the signers step (folded in at saveSigners via
// withRequesterSigner). Persistence must succeed before advancing.
async function onIdentifyContinue(): Promise<void> {
	requesterTouched.value = true
	identifyError.value = ''
	if (!requesterEmailValid.value || savingRequester.value) { return }
	savingRequester.value = true
	try {
		await saveRequester(requesterEmail.value.trim(), false)
		prepareView.value = 'signers'
	} catch {
		identifyError.value = t('libresign', 'Could not save your email. Please try again.')
	} finally {
		savingRequester.value = false
	}
}

// --- upload ---
async function uploadFile(file: File): Promise<void> {
	uploadError.value = ''
	uploading.value = true
	progress.value = 0
	try {
		await upload(file, (e) => {
			if (e.total) { progress.value = Math.round((e.loaded / e.total) * 100) }
		})
		// Success → move straight into the requester-email (Identify) step.
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
function onReplace(): void {
	// One document per workflow: replacing is not supported, so prompt to start a
	// new draft rather than fake it.
	uploadError.value = t('libresign', 'To use a different document, start a new draft.')
}

// --- signers ---
function openAdd(index: number | null): void {
	editIndex.value = index
	addOpen.value = true
}
function onAddSigner(signer: { displayName: string, email: string, requesterSponsored: boolean, description: string }): void {
	if (editIndex.value !== null && signers.value[editIndex.value]) {
		const existing = signers.value[editIndex.value]
		signers.value[editIndex.value] = { ...existing, displayName: signer.displayName, email: signer.email, requesterSponsored: signer.requesterSponsored, description: signer.description }
	} else {
		signers.value.push({ displayName: signer.displayName, email: signer.email, requesterSponsored: signer.requesterSponsored, description: signer.description })
	}
	editIndex.value = null
}
function removeSigner(index: number): void {
	signers.value.splice(index, 1)
}
async function onSaveSigners(): Promise<void> {
	prepError.value = ''
	// Fold the requester in as a genuine email signer (deduped) BEFORE building
	// the payload, so self-signing creates one real DRAFT sign request — not a
	// detached boolean — and the editor can place the requester's field.
	const effective = withRequesterSigner(signers.value, {
		requesterEmail: requesterIsSigner.value ? (requesterEmail.value.trim() || null) : null,
		requesterIsSigner: requesterIsSigner.value,
	})
	const payload = buildSignerPayload(effective, { sponsorshipEnabled })
	if (payload.length === 0) {
		prepError.value = t('libresign', 'Add at least one signer email.')
		return
	}
	savingSigners.value = true
	try {
		// Persist requester intent first so it survives OIDC/reload and is available
		// to the claim/reconciliation stage; then reconcile the signer list.
		await saveRequester(requesterEmail.value.trim() || null, requesterIsSigner.value)
		await saveSigners(payload)
	} catch {
		prepError.value = errorMessage.value || t('libresign', 'Could not save signers')
	} finally {
		savingSigners.value = false
	}
}

// --- editor ---
function openEditor(): void {
	void editor.value?.open()
}

// Keep the store binding explicit; `status` is intentionally referenced.
void status
</script>

<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-shell {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	min-height: 100%;
	min-width: 0;
	color: var(--age-fg);
	font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, system-ui, sans-serif);

	// NcIconSvgWrapper forces a --default-clickable-area (44px) minimum box on
	// every icon, which bloats button heights and floats the glyph in an
	// oversized square. Inside this focused surface the icons are decorative /
	// in-button, sized explicitly via :size, so drop the minimum and let them
	// centre on their own line. (Higher specificity than the component's own
	// scoped rule, so this wins deterministically.)
	:deep(span.icon-vue) {
		min-width: 0;
		min-height: 0;
	}

	// --- focused panel (single cohesive surface, desktop width used well) ---
	&__panel {
		width: 100%;
		max-width: 980px;
		margin: 0 auto;
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-height: 0;
		min-width: 0;
	}

	// --- centred state screens (loading / idle / expired / error) ---
	&__state {
		flex: 1 1 auto;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		text-align: center;
		padding: 48px 16px;
	}
	&__state-ic { color: var(--age-warn-text); }
	&__state-title { font-size: 1.3rem; font-weight: 600; margin: 0; }

	&__body {
		flex: 1 1 auto;
		min-width: 0;
		padding: 20px max(16px, env(safe-area-inset-left, 0px)) 48px;
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
}

.pw-muted { color: var(--age-fg-muted); font-size: 0.88rem; margin: 0; max-width: 60ch; }

.pw-err { color: var(--age-err-text); font-size: 0.82rem; margin: 0; }

.pw-savenote { color: var(--age-fg-faint); font-size: 0.78rem; margin: 0; }

// --- sponsorship explainer disclosure ---
.pw-help {
	border: 1px solid var(--age-border);
	border-radius: var(--age-radius-sm);
	background: var(--age-surface-2);

	&__toggle {
		width: 100%; display: flex; align-items: center; gap: 8px;
		border: none; background: transparent; cursor: pointer; font: inherit;
		font-size: 0.84rem; font-weight: 500; color: var(--age-fg);
		padding: 10px 12px; border-radius: var(--age-radius-sm);
		&:hover { color: var(--age-green-text); }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: -2px; }
		:deep(span.icon-vue):first-child { color: var(--age-green-text); flex: none; }
	}
	&__chev { margin-inline-start: auto; transition: transform 0.15s; }
	&__chev--open { transform: rotate(180deg); }
	&__panel {
		padding: 0 12px 12px; display: flex; flex-direction: column; gap: 7px;
		font-size: 0.82rem; color: var(--age-fg-muted); line-height: 1.5;
		p { margin: 0; }
	}
	&__h { font-weight: 600; color: var(--age-fg); }
}

.pw-file { display: block; font-size: 0.85rem; }

.pw-progress { height: 4px; background: var(--age-border); border-radius: 2px; overflow: hidden; }

.pw-progress > span { display: block; height: 100%; background: var(--age-primary); transition: width 0.2s; }

// --- progress chips (Prepare / Fields / Review) ---
.pw-chips {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: nowrap;
	gap: 6px;
	width: 100%;
	min-width: 0;
	overflow-x: auto;
	scrollbar-width: none;
	-webkit-overflow-scrolling: touch;
	padding: 12px 2px;
	border-bottom: 1px solid var(--age-border);

	&::-webkit-scrollbar { display: none; }
	&__sep { flex: none; align-self: center; width: 14px; height: 1.5px; background: var(--age-border); }
}

// On phones the chips scroll horizontally from the start rather than centring,
// so the first stage stays anchored and the row never wraps.
@media (max-width: 767px) {
	.pw-chips {
		justify-content: flex-start;
		// Fade the scrollable edges so it reads as a scroller with more stages off-screen.
		-webkit-mask-image: linear-gradient(to right, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%);
		mask-image: linear-gradient(to right, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%);
	}
}
.pw-chip {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	white-space: nowrap;
	flex: none;
	border: 1px solid var(--age-border);
	background: var(--age-surface);
	color: var(--age-fg-faint);
	border-radius: 999px;
	padding: 6px 14px 6px 7px;
	font: inherit;
	font-size: 0.82rem;
	font-weight: 500;
	min-height: 38px;
	cursor: default;
	transition: color 0.18s, background 0.18s, border-color 0.18s, box-shadow 0.18s;

	&__dot {
		width: 22px; height: 22px; flex: none; border-radius: 50%;
		display: grid; place-items: center;
		font-family: var(--age-mono); font-size: 0.72rem;
		border: 1px solid var(--age-border-strong); color: var(--age-fg-faint);
		background: var(--age-surface-2);
	}
	&__label { line-height: 1; }
	&__sub { display: inline-flex; align-items: center; gap: 4px; margin-inline-start: 2px; }
	&__sub i { width: 5px; height: 5px; border-radius: 50%; background: var(--age-border-strong); }
	&__sub i.is-on { background: var(--age-primary); }
	&__sub i.is-cur { background: var(--age-primary); box-shadow: 0 0 0 3px var(--age-focus-ring); }

	&[disabled] { cursor: not-allowed; }
	&.is-done {
		color: var(--age-fg); border-color: var(--age-green-border); cursor: pointer;
	}
	&.is-done .pw-chip__dot { background: var(--age-primary); border-color: transparent; color: var(--age-primary-text); }
	&.is-done:hover { background: var(--age-green-dim); }
	&.is-current {
		color: var(--age-fg); font-weight: 600;
		border-color: var(--age-primary); box-shadow: 0 0 0 3px var(--age-focus-ring);
	}
	&.is-current .pw-chip__dot { border-color: var(--age-primary); color: var(--age-green-text); }
	&:not([disabled]):focus-visible { outline: 2px solid var(--age-focus); outline-offset: 2px; }
}

// --- cards ---
.pw-card {
	border: 1px solid var(--age-border);
	border-radius: var(--age-radius);
	background: var(--age-surface);
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	animation: pw-rise 0.28s cubic-bezier(0.2, 0.7, 0.3, 1) both;

	&__head { display: flex; align-items: center; justify-content: space-between; gap: 9px; }
	&__title { font-size: 1rem; font-weight: 600; margin: 0; }
	&__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 2px; }
	&__next { margin-inline-start: auto; }
}

// --- fields stage status line ---
.pw-fieldstat {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 0.84rem;
	color: var(--age-fg-muted);
	:deep(span.icon-vue) { color: var(--age-green-text); flex: none; }
}

// --- review summary ---
.pw-summary { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }

.pw-sumrow {
	display: flex;
	align-items: center;
	gap: 11px;
	padding: 11px 13px;
	border: 1px solid var(--age-border);
	border-radius: var(--age-radius-sm);

	:deep(span.icon-vue) {
		flex: none;
		width: 36px;
		height: 36px;
		border-radius: 9px;
		display: grid;
		place-items: center;
		color: var(--age-green-text);
		background: var(--age-green-dim);
	}
	&__text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
	&__k { font-weight: 600; font-size: 0.88rem; color: var(--age-fg); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__v { font-size: 0.8rem; color: var(--age-fg-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__edit {
		margin-inline-start: auto;
		flex: none;
		border: none;
		background: transparent;
		color: var(--age-fg-faint);
		font: inherit;
		font-size: 0.8rem;
		cursor: pointer;
		&:hover { color: var(--age-green-text); }
	}
}

.pw-notsent {
	margin: 0;
	padding: 13px;
	border-radius: var(--age-radius-sm);
	border: 1px dashed var(--age-green-border);
	background: var(--age-green-dim);
	font-size: 0.84rem;
	color: var(--age-fg-muted);
	strong { color: var(--age-fg); font-weight: 600; }
}

// --- claim / sign-in-to-send ---
.pw-claim {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding-top: 4px;

	&__recap {
		display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px;
		font-size: 0.86rem; color: var(--age-fg);
	}
	&__recap-k { font-weight: 600; color: var(--age-fg-muted); }
	&__recap-v { color: var(--age-fg); overflow-wrap: anywhere; }
	&__recap-missing { color: var(--age-fg-faint, var(--color-text-maxcontrast)); font-style: italic; }
	&__cta { align-self: flex-start; }
}

// --- requester self-sign (signers stage) ---
.pw-selfsign {
	display: flex; flex-direction: column; gap: 10px;
	margin-top: 14px; padding-top: 14px;
	border-top: 1px solid var(--age-border, var(--color-border));

	&__check {
		display: inline-flex; align-items: center; gap: 8px;
		font-size: 0.86rem; color: var(--age-fg); cursor: pointer;
	}
	&__field { display: flex; flex-direction: column; gap: 6px; }
	&__label { font-size: 0.78rem; font-weight: 600; color: var(--age-fg-muted); }
	&__input {
		border: 1px solid var(--age-border-strong); border-radius: var(--age-radius-sm);
		padding: 9px 11px; font: inherit; font-size: 0.88rem;
		background: var(--age-surface); color: var(--age-fg); width: 100%;
		&:focus-visible { outline: none; border-color: var(--age-primary); box-shadow: 0 0 0 2px var(--age-focus-ring); }
	}
	&__note { font-size: 0.76rem; color: var(--age-fg-faint, var(--color-text-maxcontrast)); margin: 0; }
	&__pending {
		display: inline-flex; align-items: center; gap: 7px; margin: 0;
		font-size: 0.8rem; color: var(--age-focus, var(--color-primary-element));
	}
}
@media (max-width: 500px) {
	.pw-claim__cta { align-self: stretch; }
}

// --- landing hero: step preview + trust line ---
.pw-hero { gap: 16px; }
.pw-steps-preview {
	display: flex; align-items: center; justify-content: center; flex-wrap: wrap;
	gap: 6px; margin: 0; padding: 0; list-style: none;
}
.pw-steps-preview li {
	display: inline-flex; align-items: center; gap: 8px;
	font-size: 0.82rem; font-weight: 500; color: var(--age-fg-muted);
}
.pw-steps-preview li:not(.pw-steps-preview__sep) {
	padding: 9px 15px; border: 1px solid var(--age-border); border-radius: 999px; background: var(--age-surface-2);
}
.pw-steps-preview__n {
	width: 17px; height: 17px; border-radius: 50%; display: grid; place-items: center;
	font-family: var(--age-mono); font-size: 0.66rem; color: var(--age-fg-faint);
	background: var(--age-surface); border: 1px solid var(--age-border-strong);
}
.pw-steps-preview__sep { color: var(--age-fg-faint); }
.pw-trust {
	display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: center;
	margin: 0; font-size: 0.78rem; color: var(--age-fg-muted);
}

// --- resumed-draft welcome line ---
.pw-resume {
	display: flex; align-items: center; gap: 9px; margin: 0;
	padding: 10px 12px; border-radius: var(--age-radius-sm);
	background: var(--age-green-dim); border: 1px solid var(--age-green-border);
	font-size: 0.82rem; color: var(--age-green-text);
	:deep(span.icon-vue) { color: var(--age-green-text); flex: none; }
}

// --- requester-email (Identify) step ---
.pw-prepstep {
	margin: 0; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
	color: var(--age-fg-faint);
}
.pw-emailfield { display: flex; flex-direction: column; gap: 7px; }
.pw-emailfield__label { font-size: 0.78rem; font-weight: 600; color: var(--age-fg-muted); }
.pw-emailfield__input {
	border: 1px solid var(--age-border-strong); border-radius: var(--age-radius-sm);
	padding: 11px 13px; font: inherit; font-size: 0.95rem; width: 100%; max-width: 420px;
	background: var(--age-surface); color: var(--age-fg);
	&:focus-visible { outline: none; border-color: var(--age-primary); box-shadow: 0 0 0 2px var(--age-focus-ring); }
	&.is-invalid { border-color: var(--age-err-text); box-shadow: 0 0 0 2px var(--age-err-bg); }
}

// --- requester context on the signers step ---
.pw-selfsign__ctx {
	margin: 0; font-size: 0.84rem; color: var(--age-fg-muted);
	strong { color: var(--age-fg); font-weight: 600; }
}
.pw-selfsign__change {
	border: none; background: transparent; cursor: pointer; font: inherit;
	font-size: 0.82rem; color: var(--age-green-text); text-decoration: underline; text-underline-offset: 2px; padding: 0 2px;
	&:hover { color: var(--age-fg); }
	&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 2px; }
}

// --- buttons ---
.pw-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
	border: 1px solid var(--age-border-strong);
	background: var(--age-surface);
	color: var(--age-fg);
	font: inherit;
	font-size: 0.88rem;
	font-weight: 600;
	padding: 9px 16px;
	border-radius: var(--age-radius-sm);
	cursor: pointer;
	transition: background 0.16s, border-color 0.16s, transform 0.1s;

	&--primary { background: var(--age-primary); border-color: transparent; color: var(--age-primary-text); }
	&--ghost { background: transparent; border-color: transparent; color: var(--age-fg-muted); font-weight: 500; }
	&--ghost:hover { color: var(--age-fg); background: var(--age-surface-2); }
	&:active:not(:disabled) { transform: translateY(1px); }
	&:disabled { opacity: 0.5; cursor: not-allowed; }
}

// --- responsive: tighten + stack the primary action on small screens ---
@media (max-width: 500px) {
	.pw-shell__body { padding-inline: max(14px, env(safe-area-inset-left, 0px)); gap: 14px; }
	// Pin the stage's primary actions to the viewport bottom on phones, bled to
	// the card edges and clearing the home indicator, so the next step is always
	// reachable without scrolling.
	.pw-card__actions {
		position: sticky;
		bottom: 0;
		gap: 8px;
		margin: 6px -16px -16px;
		padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
		background: var(--age-surface);
		border-top: 1px solid var(--age-border);
		z-index: 2;
	}
	.pw-card__actions .pw-btn--primary { flex: 1 1 100%; }
	.pw-card__next { margin-inline-start: 0; }
}

// Respect reduced-motion: drop the ribbon/progress/chevron transitions.
.pw-viewdoc { align-self: flex-start; margin-top: 2px; }

// --- upload stage (task-focused north-star layout) ---
.pw-up {
	flex: 1 1 auto;
	min-height: 0;
	display: flex;
	flex-direction: column;
}
.pw-up__scroll {
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
	display: flex;
	flex-direction: column;
	padding: 24px 0;
}
.pw-stage {
	width: 100%;
	max-width: 560px;
	margin: auto;
	display: flex;
	flex-direction: column;
	gap: 14px;
	animation: pw-rise 0.28s cubic-bezier(0.2, 0.7, 0.3, 1) both;

	&__title {
		margin: 2px 0 0;
		font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, sans-serif);
		font-size: clamp(1.7rem, 4.2vw, 2.3rem);
		font-weight: 700;
		letter-spacing: -0.03em;
		line-height: 1.05;
		color: var(--age-fg);
	}
	&__sub {
		margin: 0;
		font-size: 0.98rem;
		line-height: 1.55;
		color: var(--age-fg-muted);
		max-width: 46ch;
	}
}
.pw-hidden-file { display: none; }
.pw-drop {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 11px;
	text-align: center;
	min-height: 200px;
	padding: 32px 22px;
	border: 1.5px dashed var(--age-border-strong);
	border-radius: var(--age-radius);
	background: var(--age-surface-2);
	cursor: pointer;
	transition: border-color 0.18s, background 0.18s;

	&:hover,
	&.is-drag,
	&:focus-visible {
		border-color: var(--age-primary);
		background: var(--age-green-dim);
		outline: none;
	}
	&--busy { cursor: default; }
	&__ic {
		width: 54px;
		height: 54px;
		border-radius: 15px;
		display: grid;
		place-items: center;
		background: var(--age-surface);
		border: 1px solid var(--age-border);
		color: var(--age-green-text);
	}
	&__title { font-size: 1rem; font-weight: 600; color: var(--age-fg); }
	&__sub { font-size: 0.85rem; color: var(--age-fg-muted); }
	&__pill {
		font-family: var(--age-mono);
		font-size: 0.72rem;
		letter-spacing: 0.06em;
		color: var(--age-fg-faint);
		padding: 4px 11px;
		border-radius: 999px;
		border: 1px solid var(--age-border);
		background: var(--age-surface);
	}
	.pw-progress { width: 100%; max-width: 280px; margin-top: 2px; }
}
.pw-stage-foot {
	flex: none;
	border-top: 1px solid var(--age-border);
	margin-inline: calc(-1 * max(16px, env(safe-area-inset-left, 0px)));
	padding: 14px max(16px, env(safe-area-inset-left, 0px)) calc(14px + env(safe-area-inset-bottom, 0px));

	&__in {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}
}

@keyframes pw-rise {
	from { opacity: 0; transform: translateY(8px); }
	to { opacity: 1; transform: none; }
}

@media (prefers-reduced-motion: reduce) {
	.pw-shell *, .pw-shell *::before, .pw-shell *::after {
		animation-duration: 0.001ms !important;
		transition-duration: 0.001ms !important;
	}
}
</style>
