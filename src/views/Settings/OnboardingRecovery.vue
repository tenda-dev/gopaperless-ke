<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Onboarding recovery')"
		:description="t('libresign', 'Send signers who do not yet have a GoPaperless account through Tendaworld onboarding, then return them to their original signing request. When disabled, the existing account-creation flow is used.')">
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
import { ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'OnboardingRecoverySettings',
})

const onboardingEnabled = ref(loadState<AdminInitialState['onboarding_recovery_enabled']>('libresign', 'onboarding_recovery_enabled', false))

// Use the admin endpoint to preserve the boolean config type.
async function save() {
	try {
		await axios.post(generateOcsUrl('/apps/libresign/api/v1/admin/onboarding-recovery-config'), {
			enabled: onboardingEnabled.value,
		})
		showSuccess(t('libresign', 'Onboarding recovery settings saved'))
	} catch (err) {
		const message = (err as { response?: { data?: { ocs?: { data?: { error?: string } } } } })?.response?.data?.ocs?.data?.error
		showError(message ?? t('libresign', 'Could not save the onboarding recovery settings'))
	}
}

function saveOnboardingEnabled() {
	save()
}

defineExpose({
	onboardingEnabled,
	saveOnboardingEnabled,
})
</script>
