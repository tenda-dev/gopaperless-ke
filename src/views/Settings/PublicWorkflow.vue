<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Public signing workflow')"
		:description="t('libresign', 'Enable the anonymous document-preparation workflow at /p/workflow, where a visitor can upload a PDF, add signers, and place signature fields as a private draft. Nothing is sent or signed until they sign in to confirm. Disabled by default.')">
		<NcCheckboxRadioSwitch
			type="switch"
			v-model="workflowEnabled"
			@update:modelValue="saveWorkflowEnabled">
			{{ t('libresign', 'Enable public signing workflow') }}
		</NcCheckboxRadioSwitch>
		<fieldset v-show="workflowEnabled" class="public-workflow__sub-section">
			<p class="public-workflow__hint">
				{{ t('libresign', 'How long an anonymous draft (and its access cookie) stays valid before it expires. The visitor must sign in to claim it within this window.') }}
			</p>
			<NcTextField v-model="expiryHours"
				type="number"
				:min="1"
				class="public-workflow__input"
				:label="t('libresign', 'Draft lifetime (hours)')"
				:placeholder="t('libresign', 'Draft lifetime (hours)')"
				@update:modelValue="saveExpiryHours" />
			<p class="public-workflow__hint">
				{{ t('libresign', 'How often the background job runs to purge expired drafts and their temporary accounts.') }}
			</p>
			<NcTextField v-model="cleanupIntervalMinutes"
				type="number"
				:min="1"
				class="public-workflow__input"
				:label="t('libresign', 'Cleanup interval (minutes)')"
				:placeholder="t('libresign', 'Cleanup interval (minutes)')"
				@update:modelValue="saveCleanupInterval" />
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
	name: 'PublicWorkflowSettings',
})

const workflowEnabled = ref(loadState<AdminInitialState['public_workflow_enabled']>('libresign', 'public_workflow_enabled', false))
const expiryHours = ref(loadState<AdminInitialState['public_workflow_expiry_hours']>('libresign', 'public_workflow_expiry_hours', 1))
const cleanupIntervalMinutes = ref(loadState<AdminInitialState['public_workflow_cleanup_interval_minutes']>('libresign', 'public_workflow_cleanup_interval_minutes', 30))

function saveWorkflowEnabled() {
	OCP.AppConfig.setValue('libresign', 'public_workflow_enabled', workflowEnabled.value ? '1' : '0')
}

// Floor both at 1 to match the server-side clamp (expiry can never be 0 hours,
// cleanup can never run every 0 minutes). The server re-clamps regardless.
function saveExpiryHours() {
	const parsed = Math.max(1, Math.floor(Number(expiryHours.value) || 1))
	expiryHours.value = parsed
	OCP.AppConfig.setValue('libresign', 'public_workflow_expiry_hours', String(parsed))
}

function saveCleanupInterval() {
	const parsed = Math.max(1, Math.floor(Number(cleanupIntervalMinutes.value) || 1))
	cleanupIntervalMinutes.value = parsed
	OCP.AppConfig.setValue('libresign', 'public_workflow_cleanup_interval_minutes', String(parsed))
}

defineExpose({
	workflowEnabled,
	expiryHours,
	cleanupIntervalMinutes,
	saveWorkflowEnabled,
	saveExpiryHours,
	saveCleanupInterval,
})
</script>

<style scoped lang="scss">
.public-workflow__sub-section {
	margin-top: 8px;
	border: none;
	padding: 0;
}

.public-workflow__hint {
	margin-top: 12px;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.public-workflow__input {
	max-width: 240px;
}
</style>
