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
		<div class="public-landing__oidc">
			<NcSelect
				v-model="selectedOidcProvider"
				:options="oidcProviderOptions"
				label="label"
				track-by="id"
				:clearable="false"
				:input-label="t('libresign', 'Public Upload Login Provider')"
				@update:model-value="saveOidcProvider" />
			<p class="public-landing__helper-text">
				{{ t('libresign', 'Select the User OIDC provider that "Sign in" and "Get started" should use. Leave on Nextcloud Login to use the default Nextcloud login page.') }}
			</p>
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'PublicLandingSettings',
})

type OidcProviderOption = { id: number, label: string }

// Keep Nextcloud Login as an explicit option rather than allowing an empty
// selection. Provider id 0 is treated as "unconfigured" by PageController,
// which preserves the default Nextcloud login flow.
const NEXTCLOUD_LOGIN_OPTION: OidcProviderOption = { id: 0, label: t('libresign', 'Nextcloud Login') }

const uploadLandingEnabled = ref(loadState<AdminInitialState['public_upload_landing_enabled']>('libresign', 'public_upload_landing_enabled', false))
const accountCreationEnabled = ref(loadState<AdminInitialState['public_account_creation_enabled']>('libresign', 'public_account_creation_enabled', false))
const acceptTermsEnabled = ref(loadState<AdminInitialState['public_accept_terms_enabled']>('libresign', 'public_accept_terms_enabled', false))

// Discovered from User OIDC's configured providers; empty when User OIDC
// isn't installed or enabled, leaving Nextcloud Login as the only option.
const discoveredOidcProviders = loadState<AdminInitialState['user_oidc_providers']>('libresign', 'user_oidc_providers', [])
const oidcProviderOptions: OidcProviderOption[] = [NEXTCLOUD_LOGIN_OPTION, ...discoveredOidcProviders]

const oidcProviderId = loadState<AdminInitialState['public_upload_login_provider_id']>('libresign', 'public_upload_login_provider_id', 0)
const selectedOidcProvider = ref<OidcProviderOption>(
	oidcProviderOptions.find(option => option.id === oidcProviderId) ?? NEXTCLOUD_LOGIN_OPTION,
)

function saveUploadLandingEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_upload_landing_enabled', uploadLandingEnabled.value ? '1' : '0')
}

function saveAccountCreationEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_account_creation_enabled', accountCreationEnabled.value ? '1' : '0')
}

function saveAcceptTermsEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_accept_terms_enabled', acceptTermsEnabled.value ? '1' : '0')
}

function saveOidcProvider(option: OidcProviderOption | null) {
	const chosen = option ?? NEXTCLOUD_LOGIN_OPTION
	selectedOidcProvider.value = chosen
	OCP.AppConfig.setValue('libresign', 'public_upload_login_provider_id', String(chosen.id))
}

defineExpose({
	uploadLandingEnabled,
	accountCreationEnabled,
	acceptTermsEnabled,
	oidcProviderOptions,
	selectedOidcProvider,
	saveUploadLandingEnabled,
	saveAccountCreationEnabled,
	saveAcceptTermsEnabled,
	saveOidcProvider,
})
</script>

<style scoped>
.public-landing__oidc {
	max-width: 400px;
	margin-top: 8px;
}

.public-landing__helper-text {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
