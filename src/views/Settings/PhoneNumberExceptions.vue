<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreSign contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Phone Number Exceptions')"
		:description="t('libresign', 'Configure payment routing for phone numbers that require an explicit mobile network or payment provider.')">
		<p class="phone-exceptions-summary">
			{{ n('libresign', '%n exception configured.', '%n exceptions configured.', overrides.length) }}
		</p>

		<NcButton variant="secondary" @click="openModal">
			{{ t('libresign', 'Manage phone exceptions') }}
		</NcButton>

		<NcDialog
			v-if="open"
			:open="open"
			:name="t('libresign', 'Phone Number Exceptions')"
			size="large"
			@update:open="handleDialogOpenChange">
			<PhoneExceptionsList
				v-if="view === 'list'"
				:overrides="overrides"
				:toggling-id="togglingId"
				:confirm-delete-id="confirmDeleteId"
				:deleting-id="deletingId"
				@add="showAddForm"
				@toggle="toggleActive"
				@edit="startEdit"
				@remove="remove"
				@confirm-delete="confirmDeleteId = $event"
				@cancel-delete="confirmDeleteId = null" />

			<PhoneExceptionForm
				v-else
				:mode="view"
				:phone="view === 'add' ? phone : editPhone"
				:error="view === 'add' ? error : editError"
				:mno="view === 'add' ? selectedMno : editMno"
				:provider="view === 'add' ? selectedProvider : editProvider"
				:region="view === 'add' ? selectedRegion : editRegion"
				:available-mnos="view === 'add' ? availableMnos : editAvailableMnos"
				:providers="overrideOptions.providers"
				:submitting="submitting"
				:saving="savingId !== null"
				@update:phone="view === 'add' ? phone = $event : editPhone = $event"
				@update:mno="view === 'add' ? selectedMno = $event : editMno = $event"
				@update:provider="view === 'add' ? selectedProvider = $event : editProvider = $event"
				@submit="view === 'add' ? addException() : saveCurrentEdit()"
				@back="backToList" />
		</NcDialog>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import PhoneExceptionForm from '@/components/Settings/PhoneExceptionForm.vue'
import PhoneExceptionsList from '@/components/Settings/PhoneExceptionsList.vue'
import { usePhoneOverrides } from '@/composables/usePhoneOverrides'
import type { PhoneOverride } from '@/composables/usePhoneOverrides'
import type { SupportedRegion } from '@/utils/phoneResolver'

const defaultRegion = loadState<SupportedRegion>('libresign', 'default_phone_region', 'KE')

const {
	open,
	view,
	overrides,
	overrideOptions,
	phone,
	error,
	submitting,
	selectedMno,
	selectedProvider,
	selectedRegion,
	editPhone,
	editError,
	editMno,
	editProvider,
	editRegion,
	editingId,
	savingId,
	togglingId,
	confirmDeleteId,
	deletingId,
	availableMnos,
	editAvailableMnos,
	showAddForm,
	startEdit,
	backToList,
	openModal,
	handleDialogOpenChange,
	addException,
	saveEdit,
	toggleActive,
	remove,
} = usePhoneOverrides(defaultRegion)

async function saveCurrentEdit(): Promise<void> {
	if (editingId.value === null) {
		return
	}

	const item = overrides.value.find(
		(override) => override.id === editingId.value,
	)

	if (!item) {
		return
	}

	await saveEdit(item)
}

defineExpose({
	open,
	overrides,
	phone,
	error,
	selectedMno,
	selectedProvider,
	editPhone,
	editError,
	editMno,
	editProvider,
	editingId,
	savingId,
	togglingId,
	confirmDeleteId,
	deletingId,
	openModal,
	addException,
	startEdit,
	saveEdit,
	toggleActive,
	remove,
	backToList,
	availableMnos,
	overrideOptions,
})
</script>

<style scoped lang="scss">
.phone-exceptions-summary {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}
</style>
