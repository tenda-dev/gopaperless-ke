<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-doc">
		<div class="pw-doc__thumb" aria-hidden="true">
			<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="24" />
		</div>
		<div class="pw-doc__meta">
			<div class="pw-doc__name" :title="name">{{ name || t('libresign', 'Your document') }}</div>
			<div class="pw-doc__sub">{{ subtitle }}</div>
		</div>
		<button v-if="canReplace" type="button" class="pw-doc__replace" @click="$emit('replace')">
			{{ t('libresign', 'Replace') }}
		</button>
	</div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { mdiFileDocumentOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
defineOptions({ name: 'PwDocumentCard' })
const props = withDefaults(defineProps<{
	name?: string
	pages?: number
	canReplace?: boolean
	/** Draft-expiry label, shown alongside the document summary (not a separate header). */
	expiry?: string
	/** Signer count, folded into the summary when > 0. */
	signerCount?: number
}>(), { name: '', pages: 0, canReplace: true, expiry: '', signerCount: 0 })
defineEmits<{ replace: [] }>()
const subtitle = computed(() => {
	const parts: string[] = []
	if (props.pages && props.pages > 0) {
		parts.push(t('libresign', '{n} page(s)', { n: props.pages }))
	}
	parts.push(t('libresign', 'Draft'))
	if (props.signerCount && props.signerCount > 0) {
		parts.push(t('libresign', '{n} signer(s)', { n: props.signerCount }))
	}
	if (props.expiry) {
		parts.push(props.expiry)
	}
	return parts.join(' · ')
})
</script>
<style scoped lang="scss">
.pw-doc {
	display: flex;
	align-items: center;
	gap: 13px;
	padding: 14px;
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: var(--age-radius, 12px);
	background: var(--age-surface, var(--color-main-background));

	&__thumb {
		flex: none; width: 44px; height: 44px; border-radius: 10px;
		display: grid; place-items: center;
		color: var(--age-green-text, var(--color-success));
		background: var(--age-green-dim, rgba(4, 213, 109, 0.12));
	}
	&__meta { min-width: 0; flex: 1 1 auto; }
	&__name { font-weight: 600; font-size: 0.95rem; color: var(--age-fg, var(--color-main-text)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__sub { font-size: 0.78rem; color: var(--age-fg-muted, var(--color-text-maxcontrast)); margin-top: 2px; }
	&__replace {
		flex: none; border: 1px solid var(--age-border, var(--color-border)); background: transparent;
		color: var(--age-fg-muted, var(--color-text-maxcontrast)); font: inherit; font-size: 0.82rem;
		padding: 6px 12px; border-radius: var(--age-radius-sm, 8px); cursor: pointer;
		&:hover { color: var(--age-fg, var(--color-main-text)); border-color: var(--age-border-strong, var(--color-border-dark)); }
	}
}
</style>
