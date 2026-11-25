<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Onboarding')"
		:description="t('libresign', 'Configure the Tendaworld onboarding destination and control whether users without a GoPaperless account are redirected there when signing a document.')">
		<div class="onboarding-url">
			<NcTextField
				v-model="registerUrl"
				type="url"
				:label="t('libresign', 'Onboarding destination URL')"
				:error="registerUrl.length > 0 && !isUrlValid"
				:helper-text="urlHelperText" />
			<!-- The onboarding URL is also used by the Register link on the login page, so it stays
				editable regardless of the recovery toggle. -->
			<p class="onboarding-url__hint">
				{{ t('libresign', 'The destination used for Tendaworld onboarding and the Register link on the login page. Defaults to the production Tendaworld URL; you can override it for this instance.') }}
			</p>
			<NcButton
				variant="primary"
				:disabled="!isUrlValid || normalisedUrl === savedUrl"
				@click="saveRegisterUrl">
				{{ t('libresign', 'Save onboarding URL') }}
			</NcButton>
		</div>

		<NcCheckboxRadioSwitch
			type="switch"
			v-model="onboardingEnabled"
			@update:modelValue="saveOnboardingEnabled">
			{{ t('libresign', 'Enable Tendaworld onboarding for unregistered signers') }}
		</NcCheckboxRadioSwitch>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'OnboardingRecoverySettings',
})

const ENDPOINT = generateOcsUrl('/apps/libresign/api/v1/admin/onboarding-recovery-config')
const DEFAULT_REGISTER_URL = 'https://tendaworld.com/get-started'

const onboardingEnabled = ref(loadState<AdminInitialState['onboarding_recovery_enabled']>('libresign', 'onboarding_recovery_enabled', false))
const registerUrl = ref(loadState<AdminInitialState['register_url']>('libresign', 'register_url', DEFAULT_REGISTER_URL))
// Last value the server accepted, so the Save button only enables on a real change.
const savedUrl = ref(registerUrl.value.trim())

const normalisedUrl = computed(() => registerUrl.value.trim())

// Non-authoritative client guard; the backend re-validates and is the source of truth.
const isUrlValid = computed(() => {
	try {
		const parsed = new URL(normalisedUrl.value)
		return parsed.protocol === 'http:' || parsed.protocol === 'https:'
	} catch {
		return false
	}
})

const urlHelperText = computed(() =>
	isUrlValid.value || registerUrl.value.length === 0
		? ''
		: t('libresign', 'Enter an absolute http(s) URL.'),
)

function readError(err: unknown): string | undefined {
	return (err as { response?: { data?: { ocs?: { data?: { message?: string } } } } })?.response?.data?.ocs?.data?.message
}

// Toggle-only save: the URL is left untouched server-side (registerUrl omitted).
async function saveOnboardingEnabled() {
	try {
		await axios.post(ENDPOINT, { enabled: onboardingEnabled.value })
		showSuccess(t('libresign', 'Onboarding recovery settings saved'))
	} catch (err) {
		showError(readError(err) ?? t('libresign', 'Could not save the onboarding recovery settings'))
	}
}

async function saveRegisterUrl() {
	if (!isUrlValid.value) {
		showError(t('libresign', 'Enter an absolute http(s) URL.'))
		return
	}
	try {
		await axios.post(ENDPOINT, { enabled: onboardingEnabled.value, registerUrl: normalisedUrl.value })
		savedUrl.value = normalisedUrl.value
		registerUrl.value = savedUrl.value
		showSuccess(t('libresign', 'Onboarding recovery settings saved'))
	} catch (err) {
		showError(readError(err) ?? t('libresign', 'Could not save the onboarding destination URL'))
	}
}

defineExpose({
	onboardingEnabled,
	registerUrl,
	saveOnboardingEnabled,
	saveRegisterUrl,
})
</script>

<style scoped lang="scss">
.onboarding-url {
	margin: 4px 0 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;

	&__hint {
		margin: 0;
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}
}
</style>
