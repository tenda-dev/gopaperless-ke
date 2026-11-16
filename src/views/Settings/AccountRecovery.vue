<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Account recovery')"
		:description="t('libresign', 'Let a signer who opens a signing link while logged in to the wrong account switch to the intended one. The flow shows a masked email hint, signs the user out, and returns them to the signing link after they authenticate as the correct account. When disabled, a wrong-account link shows a plain \'Invalid user\' message. Enabled by default.')">
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="recoveryEnabled"
			@update:modelValue="saveRecoveryEnabled">
			{{ t('libresign', 'Enable account recovery') }}
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
	name: 'AccountRecoverySettings',
})

const recoveryEnabled = ref(loadState<AdminInitialState['account_recovery_enabled']>('libresign', 'account_recovery_enabled', true))

// Use the admin endpoint to preserve the boolean config type.
async function save() {
	try {
		await axios.post(generateOcsUrl('/apps/libresign/api/v1/admin/account-recovery-config'), {
			enabled: recoveryEnabled.value,
		})
		showSuccess(t('libresign', 'Account recovery settings saved'))
	} catch (err) {
		const message = (err as { response?: { data?: { ocs?: { data?: { error?: string } } } } })?.response?.data?.ocs?.data?.error
		showError(message ?? t('libresign', 'Could not save the account recovery settings'))
	}
}

function saveRecoveryEnabled() {
	save()
}

defineExpose({
	recoveryEnabled,
	saveRecoveryEnabled,
})
</script>
