<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!-- A dismissible, in-the-moment hint shown contextually rather than as a
     permanent card. -->
<template>
	<div v-if="visible" class="pw-hint">
		<NcIconSvgWrapper class="pw-hint__ic" :path="mdiLightbulbOnOutline" :size="16" />
		<p class="pw-hint__text"><slot /></p>
		<button type="button"
			class="pw-hint__x"
			:aria-label="t('libresign', 'Dismiss')"
			@click="dismiss">
			<NcIconSvgWrapper :path="mdiClose" :size="15" />
		</button>
	</div>
</template>
<script setup lang="ts">
import { ref } from 'vue'
import { mdiClose, mdiLightbulbOnOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
defineOptions({ name: 'PwContextualHint' })
const visible = ref(true)
const emit = defineEmits<{ dismiss: [] }>()
function dismiss() {
	visible.value = false
	emit('dismiss')
}
</script>
<style scoped lang="scss">
.pw-hint {
	display: flex;
	align-items: flex-start;
	gap: 9px;
	padding: 10px 12px;
	border-radius: var(--age-radius-sm, 8px);
	background: var(--age-surface-2, var(--color-background-hover));
	border: 1px solid var(--age-border, var(--color-border));

	&__ic { color: var(--age-primary, var(--color-primary-element)); flex: none; margin-top: 1px; }
	&__text { margin: 0; flex: 1 1 auto; font-size: 0.82rem; color: var(--age-fg-muted, var(--color-text-maxcontrast)); }
	&__x {
		flex: none; border: none; background: transparent; cursor: pointer; padding: 2px;
		border-radius: 50%; color: var(--age-fg-muted, var(--color-text-maxcontrast));
		&:hover { color: var(--age-fg, var(--color-main-text)); background: var(--age-surface, var(--color-main-background)); }
	}
}
</style>
