<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Signing certificate access')"
		:description="t('libresign', 'Require users to hold a paid personal digital certificate before they can sign. While the gate is off, everyone can sign and nobody is prompted to pay.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Require a paid signing certificate to sign') }}
		</NcCheckboxRadioSwitch>
		<fieldset v-show="enabled" id="settings-certificate-validity" class="certificate-access__sub-section">
			{{ t('libresign', 'How long certificate access stays valid after payment, in days. Applies to certificates granted or renewed from now on; existing ones keep their current expiry.') }}
			<NcTextField v-model="validityDays"
				type="number"
				class="certificate-access__input"
				:label="t('libresign', 'Days certificate access is valid')"
				:placeholder="t('libresign', 'Days certificate access is valid')"
				@update:modelValue="saveValidityDays" />
		</fieldset>
	</NcSettingsSection>
</template>
<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'CertificateAccessSettings',
})

const enabled = ref(loadState<AdminInitialState['certificate_gate_enabled']>('libresign', 'certificate_gate_enabled', false))
const validityDays = ref(loadState<AdminInitialState['certificate_validity_days']>('libresign', 'certificate_validity_days', 365))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'certificate_gate_enabled', enabled.value ? '1' : '0')
}

function saveValidityDays() {
	// Minimum one day; whole days only.
	const parsed = Math.max(1, Math.floor(Number(validityDays.value) || 0))
	validityDays.value = parsed
	OCP.AppConfig.setValue('libresign', 'certificate_validity_days', String(parsed))
}

defineExpose({
	enabled,
	validityDays,
	saveEnabled,
	saveValidityDays,
})
</script>
<style scoped lang="scss">
.certificate-access__sub-section {
	margin-top: 8px;
}

.certificate-access__input {
	max-width: 240px;
}
</style>
