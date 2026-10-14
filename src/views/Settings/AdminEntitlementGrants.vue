<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Admin entitlement grants')"
		:description="t('libresign', 'Let administrators manually grant document-signing credits to a user from a dedicated support screen, for example when an operational issue blocks a customer. Disabled by default.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Enable manual entitlement grants for administrators') }}
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
	name: 'AdminEntitlementGrantsSettings',
})

const enabled = ref(loadState<AdminInitialState['admin_entitlement_grants_enabled']>('libresign', 'admin_entitlement_grants_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'admin_entitlement_grants_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
