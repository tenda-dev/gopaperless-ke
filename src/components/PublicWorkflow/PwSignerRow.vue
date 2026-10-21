<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Compact signer chip for the public workflow, simpler than the authenticated
  WorkflowSigner.vue: the email is the only identifying text; a requester-sponsored
  signer gets a compact star-in-circle status indicator (icon + accessible label,
  never colour alone). Presentation only — it is never the source of signer
  identity; edit/remove are emitted so the shell acts on the persisted draft, never
  a value reconstructed from the displayed email.

  The chip body is a single button that opens the edit dialog; the remove control
  is a separate sibling button, both keyboard-operable with accessible names.
-->
<template>
	<div class="pw-chip" :class="{ 'pw-chip--sponsored': showSponsored, 'pw-chip--you': isRequester }">
		<button type="button"
			class="pw-chip__open"
			:aria-label="t('libresign', 'Edit signer {email}', { email: signer.email })"
			@click="$emit('edit')">
			<span v-if="showSponsored"
				class="pw-chip__spons"
				role="img"
				:aria-label="t('libresign', 'Requester-sponsored signer')"
				:title="t('libresign', 'Requester-sponsored signer')">
				<NcIconSvgWrapper :path="mdiStar" :size="11" />
			</span>
			<span class="pw-chip__email">{{ signer.email }}</span>
			<span v-if="isRequester" class="pw-chip__you">{{ t('libresign', 'You') }}</span>
		</button>
		<button v-if="!isRequester"
			type="button"
			class="pw-chip__remove"
			:aria-label="t('libresign', 'Remove signer {email}', { email: signer.email })"
			@click="$emit('remove')">
			<NcIconSvgWrapper :path="mdiClose" :size="15" />
		</button>
	</div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { mdiClose, mdiStar } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import type { SignerDraft } from '../../store/publicWorkflow'

defineOptions({ name: 'PwSignerRow' })

// Capability gate mirrors IdentifySigner.vue (module-scope read of the public
// config initial-state). The indicator shows only when sponsorship is enabled
// AND the persisted draft marks this signer requester-sponsored.
const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as { sponsorship_enabled?: boolean }
).sponsorship_enabled ?? false

const props = defineProps<{ signer: SignerDraft, isRequester?: boolean }>()
defineEmits<{ edit: [], remove: [] }>()

const showSponsored = computed(() => sponsorshipEnabled && props.signer.requesterSponsored === true)
</script>
<style scoped lang="scss">
.pw-chip {
	display: inline-flex; align-items: stretch; max-width: 100%;
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: 999px;
	background: var(--age-surface, var(--color-main-background));
	overflow: hidden;

	&--sponsored { border-color: var(--age-green-border, var(--color-success)); }
	&--you { border-color: var(--age-focus, var(--color-primary-element)); }
	&__you {
		flex: none; margin-inline-start: 2px; padding: 1px 7px; border-radius: 999px;
		font-size: 0.66rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase;
		color: var(--age-focus, var(--color-primary-element));
		background: var(--age-focus-dim, var(--color-primary-element-light));
	}

	&__open {
		min-width: 0; display: inline-flex; align-items: center; gap: 7px;
		border: none; background: transparent; cursor: pointer; font: inherit;
		padding: 6px 4px 6px 12px; color: var(--age-fg, var(--color-main-text));
		&:hover { background: var(--age-surface-2, var(--color-background-hover)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: -2px; }
	}
	&__spons {
		flex: none; width: 19px; height: 19px; border-radius: 50%;
		display: grid; place-items: center;
		border: 1px solid var(--age-green-border, var(--color-success));
		color: var(--age-green-text, var(--color-success));
		background: var(--age-green-dim, transparent);
	}
	&__email {
		min-width: 0; font-size: 0.82rem; font-weight: 500;
		overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 24ch;
	}
	&__remove {
		flex: none; border: none; background: transparent; cursor: pointer;
		width: 30px; display: grid; place-items: center;
		color: var(--age-fg-faint, var(--color-text-maxcontrast));
		border-inline-start: 1px solid var(--age-border, var(--color-border));
		&:hover { color: var(--age-danger, var(--color-error)); background: var(--age-err-bg, rgba(220, 53, 69, 0.15)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: -2px; }
	}
}
</style>
