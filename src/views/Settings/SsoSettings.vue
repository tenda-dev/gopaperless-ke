<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'OIDC single sign-on handoff')"
		:description="t('libresign', 'Enable the public /sso endpoint that redirects visitors to the configured OpenID Connect provider for authentication.')">
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Enable OIDC SSO handoff') }}
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
	name: 'SsoSettings',
})

const enabled = ref(loadState<AdminInitialState['oidc_sso_handoff_enabled']>('libresign', 'oidc_sso_handoff_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'oidc_sso_handoff_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
