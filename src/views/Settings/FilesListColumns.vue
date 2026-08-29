<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Document list columns')"
		:description="t('libresign', 'Choose which optional columns appear in the documents list.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="showSigners"
			@update:modelValue="saveShowSigners">
			{{ t('libresign', 'Show the Signers column') }}
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
	name: 'FilesListColumnsSettings',
})

const showSigners = ref(loadState<AdminInitialState['files_list_show_signers']>('libresign', 'files_list_show_signers', true))

function saveShowSigners() {
	OCP.AppConfig.setValue('libresign', 'files_list_show_signers', showSigners.value ? '1' : '0')
}

defineExpose({
	showSigners,
	saveShowSigners,
})
</script>
