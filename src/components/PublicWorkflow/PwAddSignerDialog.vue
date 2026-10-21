<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Email-first add/edit-signer dialog for the public workflow. Manual entry only:
  there is NO user-directory lookup or autocomplete (public workflow supports
  email-based signers exclusively; the authenticated account-search endpoint is
  manager-gated for a reason). Duplicate-email entry is prevented inline.

  Two draft preferences follow the authenticated IdentifySigner.vue semantics:
   - "Pay for this signer" (requester sponsorship) — shown only when the
     sponsorship capability is enabled. DRAFT preference only; no certified
     signatures are reserved or used during anonymous preparation.
   - "Add custom message" — mapped to the signer `description` field, max 500.
-->
<template>
	<NcDialog v-if="open"
		:name="isEditing ? t('libresign', 'Edit signer') : t('libresign', 'Add a signer')"
		size="normal"
		@update:open="close">
		<div class="pw-add">
			<div class="pw-add__field">
				<label class="pw-add__label" for="pw-add-email">{{ t('libresign', 'Signer email') }}</label>
				<input id="pw-add-email"
					ref="emailInput"
					v-model.trim="email"
					class="pw-add__input"
					:class="{ 'pw-add__input--ok': emailValid && !isDuplicate }"
					type="email"
					autocomplete="off"
					:placeholder="t('libresign', 'name@example.com')"
					@keydown.enter.prevent="submit">
				<span v-if="isDuplicate" class="pw-add__err">{{ t('libresign', 'This signer is already in the draft.') }}</span>
				<span v-else-if="emailValid" class="pw-add__ok">
					<NcIconSvgWrapper :path="mdiCheck" :size="14" /> {{ t('libresign', 'Looks like a valid email') }}
				</span>
				<span v-else-if="emailTouched && email" class="pw-add__err">{{ t('libresign', 'Enter a valid email address.') }}</span>
			</div>

			<div class="pw-add__field">
				<label class="pw-add__label" for="pw-add-name">{{ t('libresign', 'Signer name') }}</label>
				<input id="pw-add-name"
					v-model.trim="displayName"
					class="pw-add__input"
					type="text"
					:placeholder="t('libresign', 'Enter their full name')"
					@keydown.enter.prevent="submit">
			</div>

			<!-- Requester sponsorship (draft preference only; capability-gated) -->
			<div v-if="sponsorshipEnabled" class="pw-add__pref">
				<NcCheckboxRadioSwitch :model-value="requesterSponsored"
					type="switch"
					@update:model-value="requesterSponsored = $event">
					{{ t('libresign', 'Pay for this signer') }}
				</NcCheckboxRadioSwitch>
				<p class="pw-add__prefnote">{{ t('libresign', 'You cover this signer’s signing cost using your eligible certified signatures. This is a draft preference only — nothing is reserved or used while you prepare the document.') }}</p>
			</div>

			<!-- Optional custom message mapped to the signer description -->
			<div class="pw-add__pref">
				<NcCheckboxRadioSwitch v-model="enableCustomMessage"
					type="switch"
					@update:model-value="onToggleCustomMessage">
					{{ t('libresign', 'Add custom message') }}
				</NcCheckboxRadioSwitch>
				<div v-if="enableCustomMessage" class="pw-add__msg">
					<NcTextArea v-model="description"
						:label="t('libresign', 'Custom message')"
						:placeholder="t('libresign', 'Add a personal message for this signer')"
						:maxlength="MAX_SIGNER_MESSAGE"
						:rows="3"
						resize="none" />
					<span class="pw-add__counter" :class="{ 'pw-add__counter--over': description.length > MAX_SIGNER_MESSAGE }">
						{{ description.length }} / {{ MAX_SIGNER_MESSAGE }}
					</span>
				</div>
			</div>

			<p class="pw-add__note">
				<NcIconSvgWrapper :path="mdiLockOutline" :size="15" />
				<span>{{ t('libresign', 'We never look up names from an email address. Adding a signer does not send them anything.') }}</span>
			</p>

			<div class="pw-add__actions">
				<button type="button" class="pw-add__btn" @click="close(false)">{{ t('libresign', 'Cancel') }}</button>
				<button type="button" class="pw-add__btn pw-add__btn--primary" :disabled="!emailValid || isDuplicate" @click="submit">
					{{ isEditing ? t('libresign', 'Update') : t('libresign', 'Add signer') }}
				</button>
			</div>
		</div>
	</NcDialog>
</template>
<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { mdiCheck, mdiLockOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { isDuplicateEmail, MAX_SIGNER_MESSAGE } from './signerPayload'

defineOptions({ name: 'PwAddSignerDialog' })

// Capability gate mirrors IdentifySigner.vue (module-scope read of the public
// config initial-state provided by PageController::publicWorkflow()).
const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as { sponsorship_enabled?: boolean }
).sponsorship_enabled ?? false

const props = withDefaults(defineProps<{
	open: boolean
	editing?: boolean
	/** Emails of the OTHER signers already in the draft, for duplicate prevention only (not presented). */
	existingEmails?: string[]
	initialEmail?: string
	initialName?: string
	initialSponsored?: boolean
	initialDescription?: string
}>(), {
	editing: false,
	existingEmails: () => [],
	initialEmail: '',
	initialName: '',
	initialSponsored: false,
	initialDescription: '',
})
const emit = defineEmits<{
	'update:open': [value: boolean]
	add: [signer: { displayName: string, email: string, requesterSponsored: boolean, description: string }]
}>()

const email = ref('')
const displayName = ref('')
const requesterSponsored = ref(false)
const enableCustomMessage = ref(false)
const description = ref('')
const emailTouched = ref(false)
const emailInput = ref<HTMLInputElement | null>(null)

const isEditing = computed(() => props.editing)

// Format-only validation — never a lookup.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const emailValid = computed(() => EMAIL_RE.test(email.value))
// Identity-conflict prevention: block a second signer with the same email.
// Case-insensitive; existingEmails already excludes the signer being edited.
const isDuplicate = computed(() => isDuplicateEmail(email.value, props.existingEmails))

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		email.value = props.initialEmail
		displayName.value = props.initialName
		// New signer defaults to requester-sponsored when the capability is on,
		// matching IdentifySigner.vue; editing restores the saved preference.
		requesterSponsored.value = sponsorshipEnabled && props.initialSponsored
		description.value = props.initialDescription
		enableCustomMessage.value = !!props.initialDescription
		emailTouched.value = false
		void nextTick(() => emailInput.value?.focus())
	}
})
watch(email, () => { emailTouched.value = true })

function onToggleCustomMessage(checked: boolean) {
	if (!checked) {
		description.value = ''
	}
}
function submit() {
	if (!emailValid.value || isDuplicate.value) {
		emailTouched.value = true
		return
	}
	emit('add', {
		displayName: displayName.value,
		email: email.value,
		requesterSponsored: sponsorshipEnabled ? requesterSponsored.value : false,
		description: description.value.trim(),
	})
	close(false)
}
function close(value: boolean) {
	emit('update:open', value === true)
}
</script>
<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-add {
	@include agtokens.age-tokens;
	display: flex; flex-direction: column; gap: 15px; padding: 4px 2px 2px;

	// NcIconSvgWrapper's --default-clickable-area (44px) minimum box bloats the
	// inline hint/note glyphs; drop it so they size to :size and align to text.
	// (The dialog teleports out of the shell, so it needs its own copy of this.)
	:deep(span.icon-vue) { min-width: 0; min-height: 0; }

	&__field { display: flex; flex-direction: column; gap: 6px; }
	&__label { font-size: 0.78rem; font-weight: 600; color: var(--age-fg-muted); }
	&__input {
		border: 1px solid var(--age-border-strong); border-radius: var(--age-radius-sm);
		padding: 9px 11px; font: inherit; font-size: 0.88rem;
		background: var(--age-surface); color: var(--age-fg); width: 100%;
		&:focus-visible { outline: none; border-color: var(--age-primary); box-shadow: 0 0 0 2px var(--age-focus-ring); }
		&--ok { border-color: var(--age-green-border); }
	}
	&__ok { display: inline-flex; align-items: center; gap: 5px; font-size: 0.76rem; color: var(--age-green-text); }
	&__err { font-size: 0.76rem; color: var(--age-err-text); }

	&__pref {
		display: flex; flex-direction: column; gap: 6px;
		padding: 11px 12px; border: 1px solid var(--age-border); border-radius: var(--age-radius-sm);
		background: var(--age-surface-2);
	}
	&__prefnote { margin: 0; font-size: 0.74rem; color: var(--age-fg-muted); }
	&__msg { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
	&__counter { align-self: flex-end; font-size: 0.72rem; font-family: var(--age-mono); color: var(--age-fg-faint); }
	&__counter--over { color: var(--age-err-text); }

	&__note {
		display: flex; gap: 9px; align-items: flex-start; margin: 0;
		font-size: 0.78rem; color: var(--age-fg-muted);
		background: var(--age-surface-2); border-radius: var(--age-radius-sm); padding: 10px 12px;
		:deep(.icon-vue) { flex: none; margin-top: 1px; }
	}
	&__actions { display: flex; justify-content: flex-end; gap: 10px; }
	&__btn {
		border: 1px solid var(--age-border-strong); background: var(--age-surface); color: var(--age-fg);
		font: inherit; font-size: 0.86rem; font-weight: 600; padding: 9px 16px;
		border-radius: var(--age-radius-sm); cursor: pointer;
		&--primary { background: var(--age-primary); border-color: transparent; color: var(--age-primary-text); }
		&:disabled { opacity: 0.5; cursor: not-allowed; }
	}
}
</style>
