<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreSign contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection
		:name="t('libresign', 'Phone MNO routing')"
		:description="t('libresign', 'Enable advanced mobile-network routing and per-phone override rules for payment providers.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Enable phone MNO routing v2') }}
		</NcCheckboxRadioSwitch>
	</NcSettingsSection>
	<PhoneNumberExceptions v-if="enabled" />
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import PhoneNumberExceptions from './PhoneNumberExceptions.vue'
import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'PhoneMnoRoutingV2Settings',
})

const enabled = ref(loadState<AdminInitialState['phone_mno_routing_v2_enabled']>('libresign', 'phone_mno_routing_v2_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'phone_mno_routing_v2_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
