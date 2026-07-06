<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Free signing credits')"
		:description="t('libresign', 'Grant new accounts free signing credits when they register or are invited to sign a document.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			@update:modelValue="saveEnabled">
			{{ t('libresign', 'Allow new users to receive free signing credits') }}
		</NcCheckboxRadioSwitch>
		<fieldset v-show="enabled" id="settings-free-credits-uses" class="free-credits__sub-section">
			{{ t('libresign', 'Number of free signing credits (uses) granted to each new account.') }}
			<NcTextField v-model="uses"
				type="number"
				class="free-credits__input"
				:label="t('libresign', 'Free credits per account')"
				:placeholder="t('libresign', 'Free credits per account')"
				@update:modelValue="saveUses" />
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
	name: 'FreeCreditsSettings',
})

const enabled = ref(loadState<AdminInitialState['free_credits_enabled']>('libresign', 'free_credits_enabled', true))
const uses = ref(loadState<AdminInitialState['free_credits_uses']>('libresign', 'free_credits_uses', 2))

function saveEnabled() {
	OCP.AppConfig.setValue('libresign', 'free_credits_enabled', enabled.value ? '1' : '0')
}

function saveUses() {
	const parsed = Math.max(0, Math.floor(Number(uses.value) || 0))
	uses.value = parsed
	OCP.AppConfig.setValue('libresign', 'free_credits_uses', String(parsed))
}

defineExpose({
	enabled,
	uses,
	saveEnabled,
	saveUses,
})
</script>
<style scoped lang="scss">
.free-credits__sub-section {
	margin-top: 8px;
}

.free-credits__input {
	max-width: 240px;
}
</style>
