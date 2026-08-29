<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal v-if="open"
		size="large"
		:name="t('libresign', 'Signature appearance')"
		@close="onClose">
		<div class="gp-appearance">
			<div class="gp-appearance__header">
				<h2 class="gp-appearance__title">
					{{ t('libresign', 'Signature appearance') }}
				</h2>
				<p class="gp-appearance__subtitle">
					{{ t('libresign', 'Configure customer-specific signature appearance. Select a customer group, review its current profile, adjust the rendering options, and save. One customer at a time.') }}
				</p>
			</div>

			<SignatureProfileList
				v-model:selected-group="selectedGroup"
				:groups="groups"
				:loading-groups="loadingGroups"
				:has-custom-profile="hasCustomProfile"
				:member-count="memberCount"
				:note="note"
				@search="searchGroup" />

			<template v-if="selectedGroup">
				<SignatureProfileEditor
					v-model="draft"
					:stamp-globals="stampGlobals"
					:custom-profile-count="customProfileCount"
					@reset="resetToDefault"
					@discard="discard" />

				<div class="gp-appearance__foot">
					<SignatureProfileFooter
						:dirty="dirty"
						:can-save="canSave"
						:changes="changeChips"
						:saving="saving"
						@discard="discard"
						@save="save" />
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'

import NcModal from '@nextcloud/vue/components/NcModal'

import SignatureProfileList from './SignatureProfileList.vue'
import SignatureProfileEditor from './SignatureProfileEditor.vue'
import SignatureProfileFooter from './SignatureProfileFooter.vue'
import { useSignatureProfileForm, type RawProfile, type GroupRow } from './composables/useSignatureProfileForm'
import logger from '../../../logger.js'

import '@nextcloud/password-confirmation/style.css'

defineOptions({
	name: 'SignatureProfileModal',
})

const props = withDefaults(defineProps<{
	open: boolean
	profiles?: Record<string, RawProfile>
}>(), {
	profiles: () => ({}),
})

const emit = defineEmits<{
	(event: 'update:open', value: boolean): void
	(event: 'update:profiles', value: Record<string, RawProfile>): void
}>()

const groups = ref<GroupRow[]>([])
const loadingGroups = ref(false)
const selectedGroup = ref<GroupRow | null>(null)
const saving = ref(false)
const note = ref<{ type: 'success' | 'error', message: string } | null>(null)

const {
	working,
	draft,
	stampGlobals,
	hasCustomProfile,
	memberCount,
	customProfileCount,
	dirty,
	canSave,
	changeChips,
	resetToDefault,
	discard,
} = useSignatureProfileForm(props, selectedGroup)

async function searchGroup(query = '') {
	loadingGroups.value = true
	await axios.get(generateOcsUrl('cloud/groups/details'), {
		params: { search: query, limit: 20, offset: 0 },
	})
		.then(({ data }) => {
			groups.value = data.ocs.data.groups
				.sort((a: GroupRow, b: GroupRow) => a.displayname.localeCompare(b.displayname))
		})
		.catch((error) => logger.debug('Could not search groups', { error }))
	loadingGroups.value = false
}

async function save() {
	if (!selectedGroup.value || !canSave.value) {
		return
	}
	const groupName = selectedGroup.value.displayname
	saving.value = true
	note.value = null
	try {
		await confirmPassword()

		working[selectedGroup.value.id] = {
			footer: draft.footer,
			qr: draft.qr,
			auditInfo: draft.auditInfo,
			stamp: { ...draft.stamp },
		}

		await axios.post(
			generateOcsUrl('apps/libresign/api/v1/admin/appearance-profiles/config'),
			{ profiles: working },
		)

		emit('update:profiles', JSON.parse(JSON.stringify(working)))
		note.value = {
			type: 'success',
			message: t('libresign', 'Appearance profile saved for {group}', { group: groupName }),
		}

		selectedGroup.value = null
	} catch (error) {
		logger.error('Could not save appearance profile', { error })
		note.value = {
			type: 'error',
			message: t('libresign', 'Could not save the appearance profile. Please try again.'),
		}
	} finally {
		saving.value = false
	}
}

function onClose() {
	emit('update:open', false)
}

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		note.value = null
		searchGroup('')
	}
})
</script>

<style lang="scss" scoped>
.gp-appearance {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 28px 30px 22px;

	&__header {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__title {
		margin: 0;
		font-size: 23px;
		font-weight: 700;
		letter-spacing: -0.02em;
		color: #111827;
	}

	&__subtitle {
		margin: 0;
		font-size: 14px;
		line-height: 1.55;
		color: #6b7280;
	}

	&__foot {
		border-top: 1px solid #eef0f3;
		padding-top: 6px;
	}
}

@media (max-width: 720px) {
	.gp-appearance {
		gap: 16px;
		padding: 22px 16px 16px;

		&__title {
			font-size: 20px;
		}
	}
}
</style>
