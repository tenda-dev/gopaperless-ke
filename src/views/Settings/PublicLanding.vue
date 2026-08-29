<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Public onboarding')"
		:description="t('libresign', 'Enable or disable the public-facing entry points that allow anonymous visitors to upload files, create accounts, and accept terms of service.')">
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="uploadLandingEnabled"
			@update:modelValue="saveUploadLandingEnabled">
			{{ t('libresign', 'Show public upload landing page') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="accountCreationEnabled"
			@update:modelValue="saveAccountCreationEnabled">
			{{ t('libresign', 'Allow public account creation') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="acceptTermsEnabled"
			@update:modelValue="saveAcceptTermsEnabled">
			{{ t('libresign', 'Allow users to accept terms of service publicly') }}
		</NcCheckboxRadioSwitch>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'PublicLandingSettings',
})

const uploadLandingEnabled = ref(loadState<AdminInitialState['public_upload_landing_enabled']>('libresign', 'public_upload_landing_enabled', false))
const accountCreationEnabled = ref(loadState<AdminInitialState['public_account_creation_enabled']>('libresign', 'public_account_creation_enabled', false))
const acceptTermsEnabled = ref(loadState<AdminInitialState['public_accept_terms_enabled']>('libresign', 'public_accept_terms_enabled', false))

function saveUploadLandingEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_upload_landing_enabled', uploadLandingEnabled.value ? '1' : '0')
}

function saveAccountCreationEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_account_creation_enabled', accountCreationEnabled.value ? '1' : '0')
}

function saveAcceptTermsEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_accept_terms_enabled', acceptTermsEnabled.value ? '1' : '0')
}

defineExpose({
	uploadLandingEnabled,
	accountCreationEnabled,
	acceptTermsEnabled,
	saveUploadLandingEnabled,
	saveAccountCreationEnabled,
	saveAcceptTermsEnabled,
})
</script>
