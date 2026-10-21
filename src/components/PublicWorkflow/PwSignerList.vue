<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pw-signers">
		<!-- Blank rows (e.g. the store's seeded editable row) are never rendered as
		     chips; the shell shows the contextual empty-state guidance instead. The
		     v-for index stays the signers-array index, so edit/remove still act on
		     the correct persisted row and the save payload is unchanged. -->
		<template v-for="(signer, index) in signers" :key="signer.signRequestId ?? index">
			<PwSignerRow v-if="signer.email.trim()"
				:signer="signer"
				:is-requester="isRequesterRow(signer)"
				@edit="$emit('edit', index)"
				@remove="$emit('remove', index)" />
		</template>
		<button type="button" class="pw-signers__add" @click="$emit('add')">
			<NcIconSvgWrapper :path="mdiAccountPlusOutline" :size="16" />
			{{ t('libresign', 'Add a signer') }}
		</button>
	</div>
</template>
<script setup lang="ts">
import { mdiAccountPlusOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import PwSignerRow from './PwSignerRow.vue'
import type { SignerDraft } from '../../store/publicWorkflow'
defineOptions({ name: 'PwSignerList' })
const props = defineProps<{ signers: SignerDraft[], requesterEmail?: string | null }>()

/**
 * A row is the requester when a requester email is in effect (self-signing on)
 * and this row's email matches it case-insensitively. Presentation only — it
 * badges the chip as "You" and suppresses its remove control, since the
 * requester signer is managed by the self-sign toggle, not the chip's X.
 */
function isRequesterRow(signer: SignerDraft): boolean {
	const email = (props.requesterEmail ?? '').trim().toLowerCase()
	return email.length > 0 && signer.email.trim().toLowerCase() === email
}
defineEmits<{ add: [], edit: [index: number], remove: [index: number] }>()
</script>
<style scoped lang="scss">
.pw-signers {
	// Chips wrap naturally on desktop and collapse to a single column on narrow
	// screens; the add control sits inline as the trailing item.
	display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
	&__add {
		display: inline-flex; align-items: center; gap: 7px;
		border: 1px dashed var(--age-border-strong, var(--color-border-dark)); background: transparent;
		color: var(--age-fg-muted, var(--color-text-maxcontrast)); font: inherit; font-size: 0.82rem; font-weight: 500;
		padding: 8px 13px; border-radius: 999px; cursor: pointer;
		&:hover { color: var(--age-green-text, var(--color-success)); border-color: var(--age-green-border, var(--color-success)); }
	}
}
@media (max-width: 500px) {
	.pw-signers { flex-direction: column; align-items: stretch; }
	.pw-signers__add { align-self: flex-start; }
}
</style>
