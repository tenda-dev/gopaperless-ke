<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection
		:name="t('libresign', 'Signature appearance')"
		:description="t('libresign', 'Configure customer-specific signature appearance profiles per group. Hide or show the footer, validation QR code, signature stamp or audit information for a customer group.')">
		<button
			type="button"
			class="gp-appearance-launch"
			@click="open = true">
			<svg class="gp-appearance-launch__icon" width="18" height="18" viewBox="0 0 24 24"
				fill="currentColor" aria-hidden="true">
				<path :d="mdiTuneVariant" />
			</svg>
			{{ t('libresign', 'Open signature profile settings') }}
		</button>

		<SignatureProfileModal
			v-model:open="open"
			:profiles="profiles"
			@update:profiles="profiles = $event" />
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { mdiTuneVariant } from '@mdi/js'
import { ref } from 'vue'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import SignatureProfileModal from './SignatureProfileModal.vue'
import type { RawProfile } from './SignatureProfileModal.vue'

defineOptions({
	name: 'SignatureProfileSettings',
})

const open = ref(false)
const profiles = ref<Record<string, RawProfile>>(
	loadState<Record<string, RawProfile>>('libresign', 'appearance_profiles', {}),
)
</script>

<style lang="scss" scoped>
.gp-appearance-launch {
	display: inline-flex !important;
	align-items: center;
	gap: 8px;
	min-height: 0;
	padding: 10px 18px;
	border: 1px solid #d7dce3;
	border-radius: 11px;
	background: #fff;
	color: #1e293b;
	font-size: 14px;
	font-weight: 600;
	line-height: 1;
	cursor: pointer;
	transition: background 160ms ease, border-color 160ms ease;

	&:hover {
		background: #f5f6f8;
		border-color: #16a34a;
	}

	&__icon {
		display: block;
		flex: 0 0 auto;
		color: #64748b;
	}
}
</style>
