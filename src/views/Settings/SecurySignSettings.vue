<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'SecurySign')"
		:description="t('libresign', 'Users who sign in with SecurySign sign documents with their own SecurySign certificate. Everyone else, and SecurySign users while SecurySign is down, sign with GoPaperless.')">
		<form class="securysign-settings" @submit.prevent="save">
			<NcSelect
				v-model="provider"
				:options="providerOptions"
				label="label"
				track-by="id"
				:clearable="false"
				:input-label="t('libresign', 'SecurySign login provider')" />
			<NcTextField
				v-model="url"
				:label="t('libresign', 'SecurySign address')"
				placeholder="https://securysign.com" />
			<NcPasswordField
				v-model="signingSecret"
				:label="t('libresign', 'SecurySign signing (SSC) secret')"
				:placeholder="signingSecretSet ? t('libresign', 'Saved. Type a new one to replace it.') : ''" />
			<NcTextField
				v-model="tendaworldUrl"
				:label="t('libresign', 'Enrolment site for users without a certificate')"
				placeholder="https://tendaworld.com" />
			<NcTextField
				v-model="mimiClientId"
				:label="t('libresign', 'MIMI client ID')"
				placeholder="gopaperless" />
			<NcPasswordField
				v-model="mimiClientSecret"
				:label="t('libresign', 'MIMI client secret (asks for the MIMI passkey after sign-in)')"
				:placeholder="mimiClientSecretSet ? t('libresign', 'Saved. Type a new one to replace it.') : ''" />
			<p class="securysign-settings__hint">
				{{ t('libresign', 'The issuer comes from the provider. SecurySign signs only once a provider, the address and the signing secret are set.') }}
			</p>
			<NcButton type="submit" variant="primary" :disabled="saving">
				{{ t('libresign', 'Save') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ref } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { showError, showSuccess } from '@/services/toast'
import type { AdminInitialState } from '../../types'

defineOptions({ name: 'SecurySignSettings' })

type ProviderOption = { id: number, label: string }

const OFF: ProviderOption = { id: 0, label: t('libresign', 'Off') }
const providerOptions: ProviderOption[] = [OFF, ...loadState<AdminInitialState['user_oidc_providers']>('libresign', 'user_oidc_providers', [])]
const providerId = loadState<AdminInitialState['securysign_provider_id']>('libresign', 'securysign_provider_id', 0)

const provider = ref<ProviderOption>(providerOptions.find(option => option.id === providerId) ?? OFF)
const url = ref(loadState<AdminInitialState['securysign_url']>('libresign', 'securysign_url', ''))
const tendaworldUrl = ref(loadState<AdminInitialState['tendaworld_url']>('libresign', 'tendaworld_url', ''))
const signingSecretSet = ref(loadState<AdminInitialState['securysign_signing_secret_set']>('libresign', 'securysign_signing_secret_set', false))
const signingSecret = ref('')
const mimiClientSecretSet = ref(loadState<AdminInitialState['mimi_client_secret_set']>('libresign', 'mimi_client_secret_set', false))
const mimiClientSecret = ref('')
const mimiClientId = ref(loadState<AdminInitialState['mimi_client_id']>('libresign', 'mimi_client_id', ''))
const saving = ref(false)

async function save() {
	saving.value = true
	try {
		await axios.post(generateOcsUrl('/apps/libresign/api/v1/admin/securysign-config'), {
			providerId: provider.value.id,
			url: url.value.trim(),
			tendaworldUrl: tendaworldUrl.value.trim(),
			signingSecret: signingSecret.value.trim(),
			mimiClientSecret: mimiClientSecret.value.trim(),
			mimiClientId: mimiClientId.value.trim(),
		})
		signingSecretSet.value ||= signingSecret.value.trim() !== ''
		signingSecret.value = ''
		mimiClientSecretSet.value ||= mimiClientSecret.value.trim() !== ''
		mimiClientSecret.value = ''
		showSuccess(t('libresign', 'SecurySign settings saved'))
	} catch (error) {
		const message = (error as { response?: { data?: { ocs?: { data?: { error?: string } } } } }).response?.data?.ocs?.data?.error
		showError(message ?? t('libresign', 'Could not save the SecurySign settings'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.securysign-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 400px;
}

.securysign-settings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
