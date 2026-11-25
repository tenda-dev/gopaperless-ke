<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Authenticated claim prompt for public workflows. Checks whether the current
  browser's capability cookie identifies a claimable draft and requires
  explicit confirmation before transferring it to the authenticated account.

  The capability token and authenticated session authorize the claim. When
  a requester email is stored, the server additionally verifies the
  claimant-submitted email. The UI receives only a masked hint; the account
  email is never used for comparison.
-->
<template>
	<NcDialog v-if="open"
		:name="t('libresign', 'Finish your signature request')"
		size="normal"
		:no-close="submitting"
		@update:open="onDialogToggle">
		<div class="pwc">
			<p class="pwc__lead">
				{{ t('libresign', 'We’ll move this draft into your account and open it so you can review and send. Nothing is sent yet.') }}
			</p>

			<NcNoteCard v-if="status.alreadyClaimedByMe" type="success">
				{{ t('libresign', 'You’ve already claimed this document. Continue to review and send it from your account.') }}
			</NcNoteCard>

			<template v-if="status.emailVerificationRequired">
				<NcNoteCard type="info">
					{{ t('libresign', 'This draft was prepared with the email {masked}. Enter it below to confirm it’s yours.', { masked: status.requesterEmailMasked || '—' }) }}
				</NcNoteCard>
				<div class="pwc__field">
					<label for="pwc-email">{{ t('libresign', 'Email this draft was prepared with') }}</label>
					<input id="pwc-email"
						ref="emailInputRef"
						v-model.trim="email"
						type="email"
						autocomplete="email"
						:disabled="submitting"
						:aria-invalid="emailShowError || undefined"
						:aria-describedby="emailShowError ? 'pwc-email-err' : undefined"
						@blur="emailTouched = true"
						@keydown.enter="confirm">
					<p v-if="emailShowError" id="pwc-email-err" class="pw-err" role="alert">
						{{ t('libresign', 'Enter the email this draft was prepared with.') }}
					</p>
				</div>
			</template>

			<p v-if="error" class="pwc__err" role="alert">{{ error }}</p>

			<div class="pwc__actions">
				<NcButton :disabled="submitting" @click="dismiss">
					{{ t('libresign', 'Not now') }}
				</NcButton>
				<NcButton variant="primary" :disabled="submitting || !canSubmit" @click="confirm">
					<template v-if="submitting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ status.alreadyClaimedByMe ? t('libresign', 'Continue') : t('libresign', 'Transfer & continue') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>
<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import { useManagedDialog } from '../../composables/useManagedDialog'
import { claimWorkflow, getClaimStatus, type ClaimStatus } from '../../services/publicWorkflow'
import { isPlausibleEmail } from './signerPayload'

defineOptions({ name: 'PublicWorkflowClaimDialog' })

// Server-reported claimability is the business eligibility; dismissal is
// local to this page lifecycle. The coordinator independently controls when
// an eligible dialog is permitted to present.
const claimable = ref(false)
const dismissed = ref(false)
const eligible = computed(() => claimable.value && !dismissed.value)
const { canPresent } = useManagedDialog({ id: 'pw-claim', priority: 30, eligible })
const open = computed(() => canPresent.value && eligible.value)
const submitting = ref(false)
const error = ref('')
const status = ref<ClaimStatus>({ claimable: false })

const email = ref('')
const emailTouched = ref(false)
const emailInputRef = ref<HTMLInputElement | null>(null)

// Nothing to submit as a second factor when the workflow has no stored
// requester email (legacy / token-only claim) — the server skips the gate
// entirely in that case, so the button is never blocked on it here either.
const canSubmit = computed(() => !status.value.emailVerificationRequired || isPlausibleEmail(email.value))
const emailShowError = computed(() => emailTouched.value && status.value.emailVerificationRequired === true && !isPlausibleEmail(email.value))

onMounted(async () => {
	try {
		const result = await getClaimStatus()
		if (result.claimable) {
			status.value = result
			claimable.value = true
		}
	} catch {
		// No claimable workflow for this browser, or not authenticated — stay closed.
	}
})

// Focus the email field when the dialog opens, including when presentation is
// delayed by coordinator arbitration after claimability has been resolved.
watch(open, async (isOpen) => {
	if (isOpen) {
		await nextTick()
		emailInputRef.value?.focus()
	}
})

function onDialogToggle(value: boolean): void {
	if (!value && !submitting.value) {
		dismissed.value = true
	}
}
function dismiss(): void {
	dismissed.value = true
}
async function confirm(): Promise<void> {
	if (submitting.value) {
		return
	}
	if (!canSubmit.value) {
		emailTouched.value = true
		await nextTick()
		emailInputRef.value?.focus()
		return
	}
	submitting.value = true
	error.value = ''
	try {
		const result = await claimWorkflow(status.value.emailVerificationRequired ? email.value : null)
		// Full navigation to the authenticated signing-preparation page.
		window.location.href = result.redirectUrl
	} catch (e: unknown) {
		// Whatever the server sent (including the generic, non-revealing 403 for
		// a failed email match) is safe to surface verbatim — it never carries
		// the stored or submitted address.
		const message = (e as { response?: { data?: { ocs?: { data?: { message?: string } } } } })
			?.response?.data?.ocs?.data?.message
		error.value = message || t('libresign', 'Could not claim the document. Please try again.')
		submitting.value = false
	}
}
</script>
<style scoped lang="scss">
.pwc {
	display: flex;
	flex-direction: column;
	gap: 16px;
	// Comfortable internal padding (was 4px 2px 2px), matching the AdminGrants
	// GrantCreditDialog finish so this trust-critical modal reads as deliberate.
	padding: 22px;

	&__lead {
		margin: 0;
		font-size: 0.95rem;
		line-height: 1.55;
		color: var(--color-main-text);
	}
	&__field {
		display: flex;
		flex-direction: column;
		gap: 6px;

		label {
			font-size: 0.85rem;
			font-weight: 600;
			color: var(--color-main-text);
		}
		input {
			width: 100%;
			height: 44px;
			padding: 0 12px;
			border: 2px solid var(--color-border-maxcontrast);
			border-radius: var(--border-radius-large);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 0.95rem;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: none;
			}
			&[aria-invalid="true"] {
				border-color: var(--color-error);
			}
		}
	}
	&__err {
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-error);
	}
	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 10px;
		margin-top: 4px;

		// Stack actions on narrow screens to avoid uneven wrapping. Keep the primary
		// action visually first without changing DOM or keyboard navigation order.
		@media (max-width: 400px) {
			flex-direction: column-reverse;
			align-items: stretch;

			:deep(.button-vue) {
				width: 100%;
				justify-content: center;
			}
		}
	}
}

.pw-err {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-error);
}
</style>
