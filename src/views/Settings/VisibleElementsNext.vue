<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'New signature positions editor')"
		:description="t('libresign', 'Opt in to the reworked signature positions editor. When disabled, the legacy editor is used.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Use the new signature positions editor') }}
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
	name: 'VisibleElementsNextSettings',
})

const enabled = ref(loadState<AdminInitialState['visible_elements_next_enabled']>('libresign', 'visible_elements_next_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'visible_elements_next_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
