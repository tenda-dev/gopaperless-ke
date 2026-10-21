<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Authenticated claim prompt. Mounted globally for logged-in users (App.vue);
  on mount it asks the server whether THIS browser's capability cookie maps to a
  claimable public draft. If so, it offers an explicit confirmation to transfer
  the prepared draft to the signed-in account and continue to the authenticated
  signing-request page. It never transfers silently: the POST runs only on the
  user's explicit click. The capability cookie + authenticated session are the
  authorization; the requester email is shown for reassurance/mismatch only and
  is not an authorization factor.
-->
<template>
	<NcDialog v-if="open"
		:name="t('libresign', 'Finish your signature request')"
		size="normal"
		:can-close="!submitting"
		@update:open="onDialogToggle">
		<div class="pwc">
			<p class="pwc__lead">
				{{ t('libresign', 'We’ll move this draft into your account and open it so you can review and send. Nothing is sent yet.') }}
			</p>

			<NcNoteCard v-if="status.alreadyClaimedByMe" type="success">
				{{ t('libresign', 'You’ve already claimed this document. Continue to review and send it from your account.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="mismatch" type="warning">
				{{ t('libresign', 'This draft was prepared with the email {requester}, which is different from your account email {account}. You can still claim it with this account, or switch accounts.', { requester: status.requesterEmail || '—', account: status.accountEmail || '—' }) }}
			</NcNoteCard>
			<NcNoteCard v-else-if="status.requesterEmail" type="info">
				{{ t('libresign', 'Prepared for {requester}.', { requester: status.requesterEmail }) }}
			</NcNoteCard>

			<p v-if="error" class="pwc__err">{{ error }}</p>

			<div class="pwc__actions">
				<NcButton :disabled="submitting" @click="dismiss">
					{{ t('libresign', 'Not now') }}
				</NcButton>
				<NcButton v-if="mismatch && !status.alreadyClaimedByMe"
					:disabled="submitting"
					@click="switchAccount">
					{{ t('libresign', 'Use a different account') }}
				</NcButton>
				<NcButton variant="primary" :disabled="submitting" @click="confirm">
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
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import { claimWorkflow, getClaimStatus, type ClaimStatus } from '../../services/publicWorkflow'

defineOptions({ name: 'PublicWorkflowClaimDialog' })

const open = ref(false)
const submitting = ref(false)
const error = ref('')
const status = ref<ClaimStatus>({ claimable: false })

const mismatch = computed(() => status.value.requesterEmail != null && status.value.emailMatches === false)

onMounted(async () => {
	try {
		const result = await getClaimStatus()
		if (result.claimable) {
			status.value = result
			open.value = true
		}
	} catch {
		// No claimable workflow for this browser, or not authenticated — stay closed.
	}
})

function onDialogToggle(value: boolean): void {
	if (!value && !submitting.value) {
		open.value = false
	}
}
function dismiss(): void {
	open.value = false
}
function switchAccount(): void {
	// Leave the capability cookie in place; after re-login the prompt re-appears
	// for the newly signed-in account.
	const oc = (window as unknown as { OC?: { logout?: () => void } }).OC
	if (typeof oc?.logout === 'function') {
		oc.logout()
	}
}
async function confirm(): Promise<void> {
	if (submitting.value) {
		return
	}
	submitting.value = true
	error.value = ''
	try {
		const result = await claimWorkflow()
		// Full navigation to the authenticated signing-preparation page.
		window.location.href = result.redirectUrl
	} catch (e: unknown) {
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
	}
}
</style>
