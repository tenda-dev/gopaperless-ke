<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-appearance__list">
		<div v-if="note"
			class="gp-appearance__note"
			:class="`gp-appearance__note--${note.type}`"
			role="status">
			<svg class="gp-appearance__note-icon" width="18" height="18"
				viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
				<path :d="note.type === 'success' ? mdiCheckCircle : mdiAlertCircle" />
			</svg>
			<span>{{ note.message }}</span>
		</div>

		<div class="gp-appearance__field">
			<label class="gp-appearance__label">{{ t('libresign', 'Customer group') }}</label>
			<NcSelect
				v-model="selectedGroupProxy"
				label="displayname"
				:aria-label-combobox="t('libresign', 'Customer group')"
				:options="groups"
				:loading="loadingGroups"
				:searchable="true"
				:clearable="false"
				:show-no-options="false"
				@search="$emit('search', $event)" />
		</div>

		<div v-if="selectedGroup" class="gp-appearance__summary">
			<span
				class="gp-appearance__badge"
				:class="hasCustomProfile ? 'gp-appearance__badge--custom' : 'gp-appearance__badge--default'">
				{{ hasCustomProfile ? t('libresign', 'Custom profile') : t('libresign', 'Default profile') }}
			</span>
			<span class="gp-appearance__members">
				<svg class="gp-appearance__members-icon" width="18" height="18"
					viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
					<path :d="mdiAccountGroupOutline" />
				</svg>
				{{ n('libresign', '%n member', '%n members', memberCount) }}
			</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { mdiAccountGroupOutline, mdiCheckCircle, mdiAlertCircle } from '@mdi/js'
import { t, n } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { GroupRow } from './composables/useSignatureProfileForm'

defineOptions({
	name: 'SignatureProfileList',
})

const props = defineProps<{
	groups: GroupRow[]
	loadingGroups: boolean
	selectedGroup: GroupRow | null
	hasCustomProfile: boolean
	memberCount: number
	note: { type: 'success' | 'error', message: string } | null
}>()

const emit = defineEmits<{
	search: [query: string]
	'update:selectedGroup': [group: GroupRow | null]
}>()

const selectedGroupProxy = computed<GroupRow | null>({
	get: () => props.selectedGroup,
	set: (value) => emit('update:selectedGroup', value),
})
</script>

<style lang="scss" scoped>
.gp-appearance {
	&__note {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 12px 14px;
		border: 1px solid transparent;
		border-radius: 11px;
		font-size: 14px;
		font-weight: 500;
		line-height: 1.4;

		&--success {
			background: #ecfdf3;
			border-color: #a6f4c5;
			color: #047a45;
		}

		&--error {
			background: #fef3f2;
			border-color: #fecdca;
			color: #b42318;
		}
	}

	&__note-icon {
		display: block;
		flex: 0 0 auto;
		margin-top: 1px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__label {
		font-size: 14px;
		font-weight: 500;
		color: #475569;
	}

	&__summary {
		display: flex;
		align-items: center;
		gap: 14px;
	}

	&__badge {
		font-size: 13px;
		font-weight: 600;
		padding: 4px 12px;
		border-radius: 999px;

		&--custom {
			background: #dcfce7;
			color: #047a45;
		}

		&--default {
			background: #f1f5f9;
			color: #64748b;
		}
	}

	&__members {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 14px;
		color: #64748b;
	}

	&__members-icon {
		display: block;
		flex: 0 0 auto;
	}
}
</style>
