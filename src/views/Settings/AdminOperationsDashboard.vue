<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Operations dashboard')"
		:description="t('libresign', 'Show the Operations analytics page (documents, payments, entitlements). Administrators can always view it while it is enabled; members of the groups below get access too. Disabled by default.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			:disabled="saving"
			@update:modelValue="save">
			{{ t('libresign', 'Enable the Operations dashboard') }}
		</NcCheckboxRadioSwitch>
		<div v-if="enabled" class="operations-groups">
			<label class="operations-groups__label" :for="selectId">
				{{ t('libresign', 'Additional groups allowed to view it (administrators are always allowed)') }}
			</label>
			<NcSelect :input-id="selectId"
				v-model="groupsSelected"
				label="displayname"
				:no-wrap="false"
				:close-on-select="false"
				:disabled="saving"
				:loading="loadingGroups"
				:multiple="true"
				:options="groups"
				:searchable="true"
				:show-no-options="false"
				@search-change="searchGroup"
				@update:modelValue="save" />
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { onMounted, ref } from 'vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import logger from '../../logger.js'
import type { AdminInitialState } from '../../types'

import '@nextcloud/password-confirmation/style.css'

defineOptions({
	name: 'AdminOperationsDashboardSettings',
})

type GroupRow = {
	id: string
	displayname: string
}

const selectId = 'operations-dashboard-groups'
const enabled = ref(loadState<AdminInitialState['operations_dashboard_admin_enabled']>('libresign', 'operations_dashboard_admin_enabled', false))
const savedGroupIds = loadState<AdminInitialState['operations_dashboard_admin_groups']>('libresign', 'operations_dashboard_admin_groups', [])
// Seed with ids so a saved group is shown even before the search resolves.
const groupsSelected = ref<GroupRow[]>(savedGroupIds.map((id) => ({ id, displayname: id })))
const groups = ref<GroupRow[]>([])
const loadingGroups = ref(false)
const saving = ref(false)

async function searchGroup(query: string) {
	loadingGroups.value = true
	await axios.get(generateOcsUrl('cloud/groups/details'), {
		params: { search: query, limit: 20, offset: 0 },
	})
		.then(({ data }) => {
			groups.value = data.ocs.data.groups.sort((a: GroupRow, b: GroupRow) => a.displayname.localeCompare(b.displayname))
			// Replace seeded placeholders with real display names.
			groupsSelected.value = groupsSelected.value.map((selected) => groups.value.find((g) => g.id === selected.id) ?? selected)
		})
		.catch((error) => logger.debug('Could not search by groups', { error }))
	loadingGroups.value = false
}

async function save() {
	saving.value = true
	try {
		await confirmPassword()
		await axios.post(generateOcsUrl('apps/libresign/api/v1/admin/operations-dashboard/config'), {
			enabled: enabled.value,
			groups: groupsSelected.value.map((g) => g.id),
		})
	} catch (error) {
		logger.debug('Could not save the Operations dashboard settings', { error })
	}
	saving.value = false
}

onMounted(() => searchGroup(''))

defineExpose({
	enabled,
	groupsSelected,
	groups,
	saving,
	save,
	searchGroup,
})
</script>

<style scoped lang="scss">
.operations-groups {
	margin-top: 12px;
	max-width: 480px;

	&__label {
		display: block;
		margin-bottom: 4px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
