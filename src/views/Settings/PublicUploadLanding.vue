<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Public upload landing page')"
		:description="t('libresign', 'When enabled, logged-out visitors who open GoPaperless land on the public upload page where they can start a document, instead of being sent straight to the login screen.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Show the public upload landing page to logged-out visitors') }}
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
	name: 'PublicUploadLandingSettings',
})

const enabled = ref(loadState<AdminInitialState['public_upload_landing_enabled']>('libresign', 'public_upload_landing_enabled', false))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_upload_landing_enabled', enabled.value ? '1' : '0')
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
