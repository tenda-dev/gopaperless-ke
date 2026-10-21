<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-reassure" role="note">
		<NcIconSvgWrapper class="pw-reassure__ic" :path="mdiShieldCheckOutline" :size="18" />
		<p class="pw-reassure__text">
			<strong>{{ t('libresign', 'This is a private draft.') }}</strong>
			{{ t('libresign', 'Nothing is sent, signed, or emailed — preparing it here does not notify anyone.') }}
		</p>
	</div>
</template>
<script setup lang="ts">
import { mdiShieldCheckOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
defineOptions({ name: 'PwReassurance' })
</script>
<style scoped lang="scss">
.pw-reassure {
	display: flex;
	align-items: flex-start;
	gap: 9px;
	padding: 10px 12px;
	border-radius: var(--age-radius-sm, 8px);
	background: var(--age-green-dim, rgba(4, 213, 109, 0.12));
	border: 1px solid var(--age-green-border, rgba(4, 213, 109, 0.32));

	&__ic { color: var(--age-green-text, var(--color-success)); flex: none; margin-top: 1px; }
	&__text { margin: 0; font-size: 0.82rem; color: var(--age-fg-muted, var(--color-text-maxcontrast)); }
	&__text strong { color: var(--age-fg, var(--color-main-text)); font-weight: 600; }
}
</style>
