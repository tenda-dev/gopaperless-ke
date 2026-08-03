<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Requester sponsorship')"
		:description="t('libresign', 'Allow requesters to sponsor signing for other users using their own signing credits.')">

		<NcCheckboxRadioSwitch
			v-model="enabled"
			type="switch"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Enable requester sponsorship') }}
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
	name: 'SponsorshipSettings',
})

const enabled = ref(
	loadState<AdminInitialState['sponsorship_enabled']>(
		'libresign',
		'sponsorship_enabled',
		false,
	),
)

function saveEnabled() {
	OCP.AppConfig.setValue(
		'libresign',
		'sponsorship_enabled',
		enabled.value ? '1' : '0',
	)
}

defineExpose({
	enabled,
	saveEnabled,
})
</script>
