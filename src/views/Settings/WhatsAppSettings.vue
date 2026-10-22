<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'WhatsApp signing requests')"
		:description="t('libresign', 'When on, whoever sends a document can send each signer a signing request on WhatsApp, copy their signing link or show it as a QR code. The payments service holds the phone numbers and sends the messages. When off, none of this appears.')">
		<form class="whatsapp-settings" @submit.prevent="save">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="enabled && canEnable"
				:disabled="!canEnable"
				@update:model-value="enabled = $event">
				{{ t('libresign', 'Send signing requests on WhatsApp') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="url"
				:label="t('libresign', 'Payments service address')"
				placeholder="https://gopaperless.astralyngroup.com" />
			<NcPasswordField
				v-model="apiKey"
				:label="t('libresign', 'Payments service API key')"
				:placeholder="apiKeySet ? t('libresign', 'Saved. Type a new one to replace it.') : ''" />
			<p class="whatsapp-settings__hint">
				{{ t('libresign', 'Needs a SecurySign login provider, the address and the key. Only signers who sign in with SecurySign can get a request on WhatsApp.') }}
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
import { computed, ref } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { showError, showSuccess } from '@/services/toast'
import type { AdminInitialState } from '../../types'

defineOptions({ name: 'WhatsAppSettings' })

const providerId = loadState<AdminInitialState['securysign_provider_id']>('libresign', 'securysign_provider_id', 0)
const enabled = ref(loadState<AdminInitialState['whatsapp_enabled']>('libresign', 'whatsapp_enabled', false))
const url = ref(loadState<AdminInitialState['whatsapp_api_url']>('libresign', 'whatsapp_api_url', ''))
const apiKeySet = ref(loadState<AdminInitialState['whatsapp_api_key_set']>('libresign', 'whatsapp_api_key_set', false))
const apiKey = ref('')
const saving = ref(false)

// The switch can only be on once what it needs is there, saved or being typed.
const canEnable = computed(() => providerId !== 0 && url.value.trim() !== '' && (apiKeySet.value || apiKey.value.trim() !== ''))

async function save() {
	saving.value = true
	try {
		await axios.post(generateOcsUrl('/apps/libresign/api/v1/admin/whatsapp-config'), {
			enabled: enabled.value && canEnable.value,
			url: url.value.trim(),
			apiKey: apiKey.value.trim(),
		})
		apiKeySet.value ||= apiKey.value.trim() !== ''
		apiKey.value = ''
		showSuccess(t('libresign', 'WhatsApp settings saved'))
	} catch (error) {
		const message = (error as { response?: { data?: { ocs?: { data?: { error?: string } } } } }).response?.data?.ocs?.data?.error
		showError(message ?? t('libresign', 'Could not save the WhatsApp settings'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.whatsapp-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 400px;
}

.whatsapp-settings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
