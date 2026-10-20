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
				<button type="button" class="pw-btn pw-btn--primary" @click="start">{{ t('libresign', 'Start a new draft') }}</button>
			</div>

			<!-- error -->
			<div v-else-if="phase === 'error'" class="pw-shell__state">
				<p class="pw-muted">{{ errorMessage || t('libresign', 'Something went wrong.') }}</p>
				<button type="button" class="pw-btn" @click="resume">{{ t('libresign', 'Try again') }}</button>
			</div>

			<!-- idle -->
			<div v-else-if="phase === 'idle'" class="pw-shell__state">
				<h1 class="pw-shell__state-title">{{ t('libresign', 'Prepare a document to sign') }}</h1>
				<p class="pw-muted">{{ t('libresign', 'No account needed. Your draft is kept privately and never sent until you choose to.') }}</p>
				<button type="button" class="pw-btn pw-btn--primary" @click="start">{{ t('libresign', 'Start') }}</button>
			</div>

			<!-- ready -->
			<div v-else-if="phase === 'ready'" class="pw-shell__panel">
				<nav class="pw-ribbon" :aria-label="t('libresign', 'Progress')">
					<button v-for="(label, i) in stepLabels"
						:key="i"
						type="button"
						class="pw-ribbon__step"
						:class="{ 'is-done': i < currentStep, 'is-current': i === activeStepIndex }"
						:aria-current="i === activeStepIndex ? 'step' : undefined"
						@click="goToStep(i)">
						<span class="pw-ribbon__dot">{{ i < currentStep ? '✓' : i + 1 }}</span>
						<span class="pw-ribbon__label">{{ label }}</span>
					</button>
				</nav>

				<main class="pw-shell__body">
					<!-- PREPARE: document + signers together -->
					<template v-if="activeView === 'prepare'">
						<PwReassurance />

						<section v-if="!hasDocument" class="pw-card">
							<h2 class="pw-card__title">{{ t('libresign', 'Upload your document') }}</h2>
							<p class="pw-muted">{{ t('libresign', 'Choose a PDF. It stays a private draft — nothing is sent or signed yet.') }}</p>
							<input ref="fileInput" type="file" accept="application/pdf" class="pw-file" @change="onPick">
							<p v-if="uploadError" class="pw-err">{{ uploadError }}</p>
							<div v-if="uploading" class="pw-progress"><span :style="{ width: progress + '%' }" /></div>
						</section>

						<template v-else>
							<PwDocumentCard :name="documentName"
								:pages="documentPages"
								:expiry="expiresLabel"
								:signer-count="enteredSignerCount"
								@replace="onReplace" />

							<section class="pw-card">
								<div class="pw-card__head">
									<h2 class="pw-card__title">{{ t('libresign', 'Who needs to sign?') }}</h2>
								</div>
								<PwContextualHint v-if="signers.length === 0">
									{{ t('libresign', 'Add the people who will sign. We only use these details when you choose to send — nothing is sent now.') }}
								</PwContextualHint>
								<PwSignerList :signers="signers" :requester-email="requesterIsSigner ? requesterEmail : ''" @add="openAdd(null)" @edit="openAdd($event)" @remove="removeSigner($event)" />
								<div class="pw-selfsign">
									<label class="pw-selfsign__check">
										<input v-model="requesterIsSigner" type="checkbox">
										<span>{{ t('libresign', 'I will also sign this document') }}</span>
									</label>
									<div class="pw-selfsign__field">
										<label class="pw-selfsign__label" for="pw-self-email">{{ t('libresign', 'Your email') }}</label>
										<input id="pw-self-email"
											v-model.trim="requesterEmail"
											type="email"
											class="pw-selfsign__input"
											autocomplete="email"
											:placeholder="t('libresign', 'name@example.com')">
										<p class="pw-selfsign__note">{{ t('libresign', 'Used to send your signature requests and, if you sign, to add you as a signer. Nothing is sent now.') }}</p>
									</div>
									<p v-if="requesterPending" class="pw-selfsign__pending">
										<NcIconSvgWrapper :path="mdiAccountPlusOutline" :size="15" />
										<span>{{ t('libresign', 'You’ll be added as a signer when you save.') }}</span>
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
									<button type="button" class="pw-sumrow__edit" @click="goToView('prepare')">{{ t('libresign', 'Change') }}</button>
								</li>
								<li class="pw-sumrow">
									<NcIconSvgWrapper :path="mdiAccountGroupOutline" :size="18" />
									<span class="pw-sumrow__text">
										<span class="pw-sumrow__k">{{ t('libresign', '{n} signer(s)', { n: enteredSignerCount }) }}</span>
										<span v-if="signerNames" class="pw-sumrow__v">{{ signerNames }}</span>
									</span>
									<button type="button" class="pw-sumrow__edit" @click="goToView('prepare')">{{ t('libresign', 'Edit') }}</button>
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
										{{ requesterEmail }}<template v-if="requesterIsSigner"> · {{ t('libresign', 'you will also sign') }}</template>
									</span>
									<span v-else class="pw-claim__recap-missing">{{ t('libresign', 'Add your email on the signers step') }}</span>
									<button type="button" class="pw-sumrow__edit" @click="goToView('prepare')">{{ t('libresign', 'Edit') }}</button>
								</div>
								<button type="button"
									class="pw-btn pw-btn--primary pw-claim__cta"
									:disabled="signingIn || !requesterEmailValid"
									@click="signInToSend">
									{{ signingIn ? t('libresign', 'Redirecting…') : t('libresign', 'Sign in to send your signature requests') }}
								</button>
								<p v-if="claimError" class="pw-err">{{ claimError }}</p>
								<p class="pw-savenote">{{ t('libresign', 'You’ll sign in to finish. Your signature requests are sent only after you confirm on the next step.') }}</p>
							</div>
						</section>
					</template>
				</main>
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
	mdiAccountPlusOutline,
	mdiCheckCircleOutline,
	mdiChevronDown,
	mdiClockAlertOutline,
	mdiFileDocumentOutline,
	mdiFormatColorFill,
	mdiHelpCircleOutline,
	mdiMapMarkerOutline,
} from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PwAddSignerDialog from './PwAddSignerDialog.vue'
import PwContextualHint from './PwContextualHint.vue'
import PwDocumentCard from './PwDocumentCard.vue'
import PwReassurance from './PwReassurance.vue'
import PwSignerList from './PwSignerList.vue'
import PublicVisibleElements from './PublicVisibleElements.vue'
import PublicShell from '../PublicShell.vue'
import { usePublicWorkflow, type SignerDraft } from '../../store/publicWorkflow'
import { buildSignerPayload, withRequesterSigner } from './signerPayload'

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
const editIndex = ref<number | null>(null)
const editor = ref<InstanceType<typeof PublicVisibleElements> | null>(null)
const activeView = ref<View>('prepare')
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

onMounted(resume)

// Never strand the viewer on a stage that no longer applies: without a document
// there is nothing to place or review, so fall back to Prepare.
watch(hasDocument, (has) => {
	if (!has) { activeView.value = 'prepare' }
})

// --- derived ---
const documentName = computed(() => (doc.value as { name?: string } | null)?.name || t('libresign', 'Your document'))
const documentPages = computed(() => Number((doc.value as { metadata?: { p?: number } } | null)?.metadata?.p ?? 0))
const hasEnteredSigner = computed(() => signers.value.some((s) => s.email.trim()))
// Requester email gate for the Review CTA: a minimal, forgiving email shape
// (something@something.tld). Server-side validation remains authoritative.
const requesterEmailValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(requesterEmail.value.trim()))
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

const stepLabels = computed(() => [
	t('libresign', 'Document'),
	t('libresign', 'Signers'),
	t('libresign', 'Fields'),
	t('libresign', 'Review'),
])
// Progress (completion) — drives the done checkmarks.
const currentStep = computed(() => {
	if (!hasDocument.value) { return 0 }
	if (!hasSavedSigners.value) { return 1 }
	if (!hasPlacedFields.value) { return 2 }
	return 3
})
// Which ribbon step the current view maps to — drives the highlight.
const activeStepIndex = computed(() => {
	if (activeView.value === 'fields') { return 2 }
	if (activeView.value === 'review') { return 3 }
	return hasDocument.value ? 1 : 0
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
function goToStep(i: number): void {
	activeView.value = i >= 3 ? 'review' : i === 2 ? 'fields' : 'prepare'
}

// --- upload ---
async function onPick(ev: Event): Promise<void> {
	const file = (ev.target as HTMLInputElement).files?.[0]
	if (!file) { return }
	uploadError.value = ''
	uploading.value = true
	progress.value = 0
	try {
		await upload(file, (e) => {
			if (e.total) { progress.value = Math.round((e.loaded / e.total) * 100) }
		})
	} catch {
		uploadError.value = errorMessage.value || t('libresign', 'Upload failed')
	} finally {
		uploading.value = false
		if (fileInput.value) { fileInput.value.value = '' }
	}
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

// --- progress ribbon ---
.pw-ribbon {
	display: flex;
	align-items: center;
	gap: 4px;
	overflow-x: auto;
	padding: 12px 4px;
	border-bottom: 1px solid var(--age-border);

	&__step {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		white-space: nowrap;
		color: var(--age-fg-faint);
		font-size: 0.82rem;
		font-weight: 500;
		background: transparent;
		border: none;
		border-radius: var(--age-radius-sm);
		padding: 6px 10px;
		cursor: pointer;

		&:not(:last-child)::after {
			content: '';
			width: 20px;
			height: 1px;
			background: var(--age-border);
			margin-inline-start: 4px;
		}
		&:hover { color: var(--age-fg-muted); background: var(--age-surface-2); }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 1px; }
	}
	&__dot {
		width: 20px;
		height: 20px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		border: 1px solid var(--age-border-strong);
		font-size: 0.7rem;
		font-family: var(--age-mono);
		flex: none;
	}
	&__step.is-done { color: var(--age-fg-muted); }
	&__step.is-done .pw-ribbon__dot { background: var(--age-primary); border-color: transparent; color: var(--age-primary-text); }
	&__step.is-current { color: var(--age-fg); font-weight: 600; }
	&__step.is-current .pw-ribbon__dot { border-color: var(--age-primary); color: var(--age-primary); box-shadow: 0 0 0 3px var(--age-focus-ring); }
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

	:deep(span.icon-vue) { color: var(--age-green-text); flex: none; }
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

	&--primary { background: var(--age-primary); border-color: transparent; color: var(--age-primary-text); }
	&--ghost { background: transparent; border-color: transparent; color: var(--age-fg-muted); font-weight: 500; }
	&--ghost:hover { color: var(--age-fg); background: var(--age-surface-2); }
	&:disabled { opacity: 0.5; cursor: not-allowed; }
}

// --- responsive: tighten + stack the primary action on small screens ---
@media (max-width: 500px) {
	.pw-shell__body { padding-inline: max(14px, env(safe-area-inset-left, 0px)); gap: 14px; }
	.pw-card__actions { gap: 8px; }
	.pw-card__actions .pw-btn--primary { flex: 1 1 100%; }
	.pw-card__next { margin-inline-start: 0; }
}
</style>
