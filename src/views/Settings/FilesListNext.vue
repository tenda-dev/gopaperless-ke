<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'New document list')"
		:description="t('libresign', 'Opt in to the reworked documents list. When disabled, the legacy list is used.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Use the new documents list') }}
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
	name: 'FilesListNextSettings',
})

const enabled = ref(loadState<AdminInitialState['files_list_next_enabled']>('libresign', 'files_list_next_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'files_list_next_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
