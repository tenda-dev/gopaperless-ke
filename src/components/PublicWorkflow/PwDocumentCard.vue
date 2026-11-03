<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-doc">
		<div class="pw-doc__thumb" aria-hidden="true">
			<NcIconSvgWrapper :path="mdiFilePdfBox" :size="24" />
		</div>
		<div class="pw-doc__meta">
			<div class="pw-doc__name" :title="name">{{ name || t('libresign', 'Your document') }}</div>
			<div class="pw-doc__sub">{{ subtitle }}</div>
		</div>
		<!-- Optional step-tracking status pill (PublicWorkflowCurrentShell's stage
		     control). Additive and opt-in: omitted entirely when statusLabel isn't
		     passed, so the legacy card (PublicWorkflowShell) renders exactly as before. -->
		<span v-if="statusLabel" class="pw-doc__status" :class="`pw-doc__status--${statusVariant}`">{{ statusLabel }}</span>
		<button v-else type="button" class="pw-doc__view" @click="$emit('view')">
			{{ t('libresign', 'View') }}
		</button>
	</div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { mdiFilePdfBox } from '@mdi/js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
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
	/** Step-tracking status pill text (e.g. "Set positions", "Ready"). Omit to keep the default "View" button. */
	statusLabel?: string
	statusVariant?: 'draft' | 'purple' | 'ready'
}>(), { name: '', pages: 0, canReplace: true, expiry: '', signerCount: 0, statusLabel: '', statusVariant: 'draft' })
defineEmits<{ replace: [], view: [] }>()
const subtitle = computed(() => {
	const parts: string[] = []
	if (props.pages && props.pages > 0) {
		parts.push(n('libresign', '%n page', '%n pages', props.pages))
	}
	parts.push(t('libresign', 'Draft'))
	if (props.signerCount && props.signerCount > 0) {
		parts.push(n('libresign', '%n signer', '%n signers', props.signerCount))
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
	&__view {
		flex: none; border: none; background: transparent;
		color: var(--age-green-text, var(--color-success)); font: inherit; font-size: 0.82rem; font-weight: 600;
		padding: 6px 10px; border-radius: var(--age-radius-sm, 8px); cursor: pointer;
		transition: background 0.16s;
		&:hover { background: var(--age-green-dim, rgba(4, 213, 109, 0.12)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: 2px; }
	}
	&__status {
		flex: none; font-size: 0.7rem; font-weight: 600; padding: 3px 9px; border-radius: 999px;
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
		background: var(--age-hover, var(--color-background-hover));
		border: 1px solid var(--age-border, var(--color-border));
		&--purple { color: #5b5bd6; background: #ecebfb; border-color: rgba(91, 91, 214, 0.35); }
		&--ready { color: var(--age-green-text, var(--color-success)); background: var(--age-green-dim, rgba(4, 213, 109, 0.12)); border-color: var(--age-green-dim, rgba(4, 213, 109, 0.3)); }
	}
}
</style>
