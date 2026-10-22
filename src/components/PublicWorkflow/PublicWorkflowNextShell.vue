<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  PublicWorkflowNext — desktop redesign of the anonymous document-prep workspace.

  A parallel shell to PublicWorkflowShell, built INERT: reachable only at the new
  /p/workflow-next route so the current single-column flow is untouched and the
  two can be A/B'd on staging. It reuses the SAME public store, the SAME leaf
  components (PwSignerList / PwAddSignerDialog / PwContextualHint / PwReassurance)
  and the SAME editing modal (PublicVisibleElements). Only the layout changes.

  Desktop: a floating chip strip (Prepare · Fields · Review) over a persistent
  document stage (the real PDF, always in view, with placed fields projected
  read-only) and a task rail with a pinned Back/Continue footer. Under 860px it
  collapses to today's mobile — a scrollable chip strip, a peek document and a
  stacked task — so mobile does not regress. Draft preparation ONLY: no send,
  account handoff, payments, credits or signing.
-->
<template>
	<PublicShell fill>
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

			<!-- ready: three-pane workspace -->
			<div v-else-if="phase === 'ready'" class="pwn" :class="{ 'is-doc-hidden': !docPeek }">
				<!-- mobile only: scrim behind the opt-in document sheet -->
				<div class="pwn__scrim" aria-hidden="true" @click="docPeek = false" />
				<div class="pwn__ws">
					<!-- chip strip -->
					<nav class="pwn__strip" :aria-label="t('libresign', 'Progress')">
						<div class="pwn__strip-inner">
							<template v-for="(label, i) in stepLabels" :key="i">
								<button
									type="button"
									class="pwn-chip"
									:class="{ 'is-done': i < activeStepIndex && stepReachable(i), 'is-current': i === activeStepIndex }"
									:aria-current="i === activeStepIndex ? 'step' : undefined"
									:disabled="!stepReachable(i)"
									@click="goToStep(i)">
									<span class="pwn-chip__n">
										<NcIconSvgWrapper v-if="i < activeStepIndex && stepReachable(i)" :path="mdiCheck" :size="12" />
										<template v-else>{{ i + 1 }}</template>
									</span>
									<span class="pwn-chip__label">{{ label }}</span>
									<span v-if="i === 0 && activeStepIndex === 0" class="pwn-chip__sub" aria-hidden="true">
										<i v-for="n in 3" :key="n" :class="{ 'is-on': n - 1 < prepareSubIndex, 'is-cur': n - 1 === prepareSubIndex }" />
									</span>
								</button>
								<span v-if="i < stepLabels.length - 1" class="pwn__sep" aria-hidden="true" />
							</template>
						</div>
						<button type="button" class="pwn__peek" :aria-pressed="docPeek" @click="docPeek = !docPeek">
							<NcIconSvgWrapper :path="docPeek ? mdiEyeOffOutline : mdiEyeOutline" :size="14" />
							{{ docPeek ? t('libresign', 'Hide') : t('libresign', 'Preview') }}
						</button>
					</nav>

					<!-- document stage: persistent pane (desktop) / opt-in bottom-sheet (mobile) -->
					<div class="pwn__stage">
						<span class="pwn__grab" aria-hidden="true" />
						<button type="button" class="pwn__sheet-close" :aria-label="t('libresign', 'Close document')" @click="docPeek = false">
							<NcIconSvgWrapper :path="mdiClose" :size="18" />
						</button>
						<PwDocumentStage :document="(doc as any)" :pages="documentPages" :expiry="expiresLabel" :active="true" />
					</div>

					<!-- task rail -->
					<section class="pwn__rail">
						<div class="pwn__rail-scroll">
							<!-- panel-top navigation: Back + a single step label -->
							<div class="pwn__pnav">
								<button v-if="panelNav.back" type="button" class="pwn__back" @click="panelNav.back()">
									<NcIconSvgWrapper :path="mdiArrowLeft" :size="15" />
									{{ t('libresign', 'Back') }}
								</button>
								<span v-else class="pwn__back-spacer" aria-hidden="true" />
								<span class="pwn__stepcount">{{ panelNav.step }}</span>
							</div>

							<!-- PREPARE -->
							<template v-if="activeView === 'prepare'">
								<p v-if="justResumed" class="pw-resume" role="status">
									<NcIconSvgWrapper :path="mdiClockOutline" :size="16" />
									<span>{{ expiresLabel ? t('libresign', 'We kept your draft. {e}.', { e: expiresLabel }) : t('libresign', 'We kept your draft.') }}</span>
								</p>

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
											<span class="pw-drop__sub">{{ t('libresign', 'Kept privately for 72 hours.') }}</span>
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
									<h2 class="pwn__title">{{ addSignersTitle }}</h2>
									<p class="pw-muted">
										{{ t('libresign', 'The people who will sign this document. Preparing as {email} — kept separate from the signers.', { email: requesterEmail || t('libresign', 'your email') }) }}
										<button type="button" class="pw-selfsign__change" @click="prepareView = 'identify'">{{ t('libresign', 'Change') }}</button>
									</p>
									<PwContextualHint v-if="enteredSignerCount === 0">
										{{ t('libresign', 'Add the people who will sign. We only use these details when you choose to send — nothing is sent now.') }}
									</PwContextualHint>
									<div class="pwn__signers" :class="{ 'is-busy': signersSaving }" :aria-busy="signersSaving">
										<PwSignerList :signers="signers" :requester-email="requesterSignerEmail" @add="openAdd(null)" @edit="openAdd($event)" @remove="removeSigner($event)" />
									</div>
									<div v-if="sponsorshipEnabled" class="pw-help">
										<p class="pw-help__copy">
											<strong>{{ t('libresign', 'Cover your signers’ signing costs.') }}</strong>
											{{ t('libresign', 'Choose who to cover, and we’ll use your available certified signatures to pay for their eligible signing costs.') }}
										</p>
										<p class="pw-help__note">
											{{ t('libresign', 'Nothing is sent until you choose to continue.') }}
										</p>
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
								<h2 class="pwn__title">{{ t('libresign', 'Place signature fields') }}</h2>
								<p class="pw-muted">{{ t('libresign', 'Put each person’s signature where it belongs. They appear on the document as you place them.') }}</p>
								<PwContextualHint v-if="!hasSavedSigners">
									{{ t('libresign', 'Save at least one signer before placing fields.') }}
								</PwContextualHint>
								<p v-else class="pw-fieldstat">
									<NcIconSvgWrapper :path="hasPlacedFields ? mdiCheckCircleOutline : mdiMapMarkerOutline" :size="16" />
									<span>{{ hasPlacedFields
										? t('libresign', '{n} field(s) placed across {s} signer(s)', { n: visibleElements.length, s: enteredSignerCount })
										: t('libresign', 'No fields placed yet.') }}</span>
								</p>
								<div class="pwn__action">
									<button type="button" class="pw-btn pw-btn--primary" :disabled="!canEnterFields" @click="openEditor">
										<NcIconSvgWrapper :path="mdiFormatColorFill" :size="18" />
										{{ hasPlacedFields ? t('libresign', 'Edit signature fields') : t('libresign', 'Place signature fields') }}
									</button>
								</div>
								<PwReassurance />
							</template>

							<!-- REVIEW -->
							<template v-else>
								<h2 class="pwn__title">{{ t('libresign', 'Review your draft') }}</h2>
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
								<p class="pw-notsent">
									<strong>{{ t('libresign', 'Nothing has been sent, signed, or emailed.') }}</strong>
									{{ t('libresign', 'Your draft is saved and available for 72 hours — you can keep editing or come back later.') }}
								</p>
								<div class="pw-claim">
									<div class="pw-claim__recap">
										<span class="pw-claim__recap-k">{{ t('libresign', 'Sending as') }}</span>
										<span v-if="requesterEmailValid" class="pw-claim__recap-v">
											{{ requesterEmail }}<template v-if="selfSign"> · {{ t('libresign', 'you will also sign') }}</template>
										</span>
										<span v-else class="pw-claim__recap-missing">{{ t('libresign', 'Add your email to continue') }}</span>
										<button type="button" class="pw-sumrow__edit" @click="goToPrepareIdentify">{{ requesterEmailValid ? t('libresign', 'Edit') : t('libresign', 'Add your email') }}</button>
									</div>
									<p v-if="claimError" class="pw-err">{{ claimError }}</p>
									<p class="pw-savenote">{{ t('libresign', 'You’ll sign in to finish. Your signature requests are sent only after you confirm on the next step.') }}</p>
								</div>
							</template>
						</div>

						<!-- pinned footer: the forward move only (Back lives at the panel top) -->
						<div class="pwn__rail-foot">
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
									{{ signingIn ? t('libresign', 'Redirecting…') : t('libresign', 'Sign in to continue') }} →
								</button>
							</template>
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
	mdiCheck,
	mdiClose,
	mdiCheckCircleOutline,
	mdiChevronDown,
	mdiClockAlertOutline,
	mdiClockOutline,
	mdiEyeOffOutline,
	mdiEyeOutline,
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
import PwDocumentStage from './PwDocumentStage.vue'
import PwReassurance from './PwReassurance.vue'
import PwSignerList from './PwSignerList.vue'
import PublicVisibleElements from './PublicVisibleElements.vue'
import PublicShell from '../PublicShell.vue'
import { usePublicWorkflow, type SignerDraft } from '../../store/publicWorkflow'
import { buildSignerPayload, isPlausibleEmail } from './signerPayload'

defineOptions({ name: 'PublicWorkflowNextShell' })

const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as { sponsorship_enabled?: boolean }
).sponsorship_enabled ?? false

// Client-side upload cap, mirroring the authenticated RequestPicker
// (MAX_FILE_SIZE_MB = 25). The public backend only enforces the instance limit,
// so this keeps the public flow consistent with the rest of the product and makes
// the "25MB max" copy true.
const MAX_FILE_SIZE_MB = 25
const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024

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
// The document stage is a persistent pane on desktop; on mobile it is an opt-in
// bottom-sheet, closed by default so the task flow leads (a user-directed journey,
// matching /p/workflow mobile). docPeek drives the sheet; it is inert on desktop.
const docPeek = ref(false)
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
const helpOpen = ref(false)
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
const editRow = computed<SignerDraft | null>(() => (editIndex.value !== null ? signers.value[editIndex.value] ?? null : null))
const otherSigners = computed(() => signers.value.filter((_, i) => i !== editIndex.value && signers.value[i].email.trim()))

const stepLabels = computed(() => [
	t('libresign', 'Prepare'),
	t('libresign', 'Fields'),
	t('libresign', 'Review'),
])
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
	if (i === 0) { return true }
	// Fields is reachable only when signer changes are settled (saved, not in flight
	// or failed); Review additionally needs a placed field.
	if (i === 1) { return canEnterFields.value }
	return canEnterFields.value && hasPlacedFields.value
}
const prepareSubIndex = computed(() => ({ upload: 0, identify: 1, signers: 2 })[prepareView.value] ?? 0)
function goToStep(i: number): void {
	if (!stepReachable(i)) { return }
	activeView.value = i >= 2 ? 'review' : i === 1 ? 'fields' : 'prepare'
}
function goToPrepareUpload(): void { activeView.value = 'prepare'; prepareView.value = 'upload' }
function goToPrepareIdentify(): void { activeView.value = 'prepare'; prepareView.value = 'identify' }
function goToPrepareSigners(): void { activeView.value = 'prepare'; prepareView.value = 'signers' }

// Approach A: a single step label + a Back affordance live at the top of the
// panel; the footer carries only the forward move. `back` is null on the first
// screen (Upload), where there is nowhere to step back to.
const panelNav = computed<{ step: string, back: null | (() => void) }>(() => {
	if (activeView.value === 'fields') { return { step: t('libresign', 'Step 2 · Fields'), back: goToPrepareSigners } }
	if (activeView.value === 'review') { return { step: t('libresign', 'Step 3 · Review'), back: () => goToView('fields') } }
	if (prepareView.value === 'identify') { return { step: t('libresign', 'Prepare · Step 2 of 3'), back: () => { prepareView.value = 'upload' } } }
	if (prepareView.value === 'signers') { return { step: t('libresign', 'Prepare · Step 3 of 3'), back: () => { prepareView.value = 'identify' } } }
	return { step: t('libresign', 'Prepare · Step 1 of 3'), back: null }
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
	// Reject over-size files client-side before upload, matching RequestPicker.
	if (file.size > MAX_FILE_SIZE_BYTES) {
		uploadError.value = t('libresign', 'That file is larger than {n}MB. Choose a smaller PDF.', { n: MAX_FILE_SIZE_MB })
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
	font-family: var(--tenda-font-family, var(--font-face, sans-serif));
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
	grid-template-rows: auto minmax(0, 1fr);
	grid-template-areas: "strip rail" "stage rail";
}

/* chip strip — floating, over the document column */
.pwn__strip {
	grid-area: strip;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 14px 18px;
	background: transparent;
	overflow-x: auto;
	scrollbar-width: none;

	&::-webkit-scrollbar {
		display: none;
	}
}

.pwn__strip-inner {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	width: 100%;
	max-width: 760px;
	margin: 0 auto;
}

.pwn__peek {
	display: none;
}

.pwn-chip {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	white-space: nowrap;
	flex: none;
	border: 1px solid var(--age-border, var(--color-border));
	background: var(--age-surface, var(--color-main-background));
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	border-radius: 999px;
	padding: 6px 15px 6px 7px;
	font: inherit;
	font-size: 12.5px;
	font-weight: 500;
	min-height: 36px;
	cursor: default;
	transition: border-color 0.2s, color 0.2s, box-shadow 0.2s, background 0.2s;

	&[disabled] {
		cursor: default;
	}
}

.pwn-chip__n {
	width: 21px;
	height: 21px;
	flex: none;
	border-radius: 50%;
	display: grid;
	place-items: center;
	font-family: var(--tenda-font-mono, monospace);
	font-size: 10.5px;
	border: 1.5px solid var(--age-border-strong, var(--color-border-dark));
	color: var(--age-fg-faint, var(--color-text-maxcontrast));
	background: var(--age-surface-2, var(--color-background-hover));
	transition: all 0.2s;
}

.pwn-chip.is-done {
	color: var(--age-fg, var(--color-main-text));
	border-color: var(--age-success-border, rgba(4, 213, 109, 0.34));
	cursor: pointer;
}

.pwn-chip.is-done:hover {
	background: var(--age-success-tint, rgba(4, 213, 109, 0.12));
}

.pwn-chip.is-done .pwn-chip__n {
	background: var(--age-success, #04d56d);
	border-color: transparent;
	color: var(--age-on-success, #06351f);
}

.pwn-chip.is-current {
	color: var(--age-fg, var(--color-main-text));
	font-weight: 600;
	border-color: var(--age-success, var(--color-primary-element));
	box-shadow: 0 0 0 3px var(--age-success-tint, rgba(4, 213, 109, 0.12));
}

.pwn-chip.is-current .pwn-chip__n {
	border-color: var(--age-success, var(--color-primary-element));
	color: var(--age-success-fg, #0a6636);
	background: var(--age-surface, var(--color-main-background));
}

.pwn-chip__sub {
	display: inline-flex;
	gap: 4px;
	margin-inline-start: 1px;
}

.pwn-chip__sub i {
	width: 5px;
	height: 5px;
	border-radius: 50%;
	background: var(--age-border-strong, var(--color-border-dark));
	transition: all 0.2s;
}

.pwn-chip__sub i.is-on {
	background: var(--age-success, #04d56d);
}

.pwn-chip__sub i.is-cur {
	background: var(--age-success, #04d56d);
	box-shadow: 0 0 0 3px var(--age-success-tint, rgba(4, 213, 109, 0.12));
}

.pwn__sep {
	flex: none;
	align-self: center;
	width: 16px;
	height: 1.5px;
	background: var(--age-border, var(--color-border));
}

/* document stage */
.pwn__stage {
	grid-area: stage;
	min-width: 0;
	min-height: 0;
	overflow: hidden;
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
	justify-content: space-between;
	gap: 10px;
	min-height: 30px;
	margin-bottom: -4px;
}

.pwn__back {
	display: inline-flex;
	align-items: center;
	gap: 6px;
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

.pwn__back-spacer {
	width: 1px;
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

.pwn__rail-foot {
	flex: none;
	border-top: 1px solid var(--age-border, var(--color-border));
	background: var(--age-surface, var(--color-main-background));
	padding: 14px 26px calc(14px + env(safe-area-inset-bottom, 0px));
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 8px;
}

/* Forward CTA spans the footer within its gutters; any status/error stacks above it. */
.pwn__rail-foot .pw-btn--primary {
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
.pw-resume {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12.5px;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	margin: 0;
}

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
	padding: 12px 14px;
	background: var(--age-surface-2, var(--color-background-hover));
}

.pw-help__copy {
	margin: 0;
	font-size: 12.5px;
	line-height: 1.55;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));

	strong {
		color: var(--age-fg, var(--color-main-text));
	}
}

.pw-help__note {
	margin: 6px 0 0;
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

.pw-claim__recap-v {
	color: var(--age-fg, var(--color-main-text));
	font-weight: 600;
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

	/* Mirror /p/workflow's chip strip: the chips are a flat horizontal scroller
	   (nowrap, overflow-x:auto, edge-fade) anchored from the start; the Preview
	   toggle is pinned beside them and never scrolls. */
	.pwn__strip {
		flex: none;
		justify-content: flex-start;
		gap: 8px;
		overflow: visible;
	}

	.pwn__strip-inner {
		flex: 1 1 auto;
		min-width: 0;
		width: auto;
		max-width: none;
		flex-wrap: nowrap;
		justify-content: flex-start;
		overflow-x: auto;
		scrollbar-width: none;
		-webkit-overflow-scrolling: touch;
		-webkit-mask-image: linear-gradient(to right, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%);
		mask-image: linear-gradient(to right, transparent 0, #000 18px, #000 calc(100% - 18px), transparent 100%);
	}

	.pwn__strip-inner::-webkit-scrollbar {
		display: none;
	}

	.pwn__peek {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		flex: none;
		background: none;
		border: none;
		font: inherit;
		font-size: 12px;
		color: var(--age-success-fg, #0a6636);
		cursor: pointer;
	}

	/* Task-first: the rail owns the whole screen; the document is opt-in. */
	.pwn__rail {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 0;
		border-inline-start: none;
		border-top: 1px solid var(--age-border, var(--color-border));
	}

	.pwn__rail-scroll {
		min-width: 0;
	}

	.pwn__strip {
		min-width: 0;
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
