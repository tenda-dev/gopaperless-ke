<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Signer entry for the public workflow. Responsive presentation: a compact pill
  chip on desktop, and a full-width rectangular row (avatar + email + remove) on
  mobile, matching the mobile mockup. The email is the only identifying text; the
  avatar initials derive from the email, never the display name. A
  requester-sponsored signer gets a compact star status indicator (icon +
  accessible label, never colour alone).

  The body is a single button that opens the edit dialog; the remove control is a
  separate sibling button, both keyboard-operable with accessible names.
  Presentation only — edit/remove are emitted so the shell acts on the persisted
  draft, never a value reconstructed from the displayed email.
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
				<NcIconSvgWrapper :path="mdiStar" :size="12" />
			</span>
			<span v-else class="pw-chip__av" aria-hidden="true">{{ initials }}</span>
			<span class="pw-chip__email">{{ signer.email }}</span>
			<span v-if="isRequester" class="pw-chip__you">{{ t('libresign', 'You') }}</span>
		</button>
		<button v-if="!isRequester"
			type="button"
			class="pw-chip__remove"
			:aria-label="t('libresign', 'Remove signer {email}', { email: signer.email })"
			@click="$emit('remove')">
			<NcIconSvgWrapper :path="mdiClose" :size="16" />
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
// Avatar initials from the email local-part only (never the display name).
const initials = computed(() => ((props.signer.email || '').trim().split('@')[0].slice(0, 2).toUpperCase() || '#'))
</script>
<style scoped lang="scss">
// --- desktop: compact pill chip ---
.pw-chip {
	display: inline-flex;
	align-items: stretch;
	max-width: 100%;
	border: 1px solid var(--age-border, var(--color-border));
	border-radius: 999px;
	background: var(--age-surface, var(--color-main-background));
	overflow: hidden;
	animation: pw-chip-in 0.2s ease both;

	&--sponsored { border-color: var(--age-green-border, var(--color-success)); }
	&--you { border-color: var(--age-focus, var(--color-primary-element)); }

	&__open {
		min-width: 0;
		display: inline-flex;
		align-items: center;
		gap: 8px;
		border: none;
		background: transparent;
		cursor: pointer;
		font: inherit;
		padding: 6px 10px 6px 12px;
		color: var(--age-fg, var(--color-main-text));
		transition: background 0.16s;
		&:hover { background: var(--age-surface-2, var(--color-background-hover)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: -2px; }
	}
	// Avatar is a mobile-row affordance; hidden in the desktop pill.
	&__av { display: none; }
	&__spons {
		flex: none;
		width: 19px;
		height: 19px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		border: 1px solid var(--age-green-border, var(--color-success));
		color: var(--age-green-text, var(--color-success));
		background: var(--age-green-dim, transparent);
	}
	&__email {
		min-width: 0;
		font-size: 0.82rem;
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		max-width: 24ch;
	}
	&__you {
		flex: none;
		padding: 1px 7px;
		border-radius: 999px;
		font-size: 0.66rem;
		font-weight: 600;
		letter-spacing: 0.02em;
		text-transform: uppercase;
		color: var(--age-focus, var(--color-primary-element));
		background: var(--age-focus-dim, var(--color-primary-element-light));
	}
	// Remove control: no divider border, just the glyph.
	&__remove {
		flex: none;
		width: 32px;
		border: none;
		background: transparent;
		cursor: pointer;
		display: grid;
		place-items: center;
		color: var(--age-fg-faint, var(--color-text-maxcontrast));
		transition: color 0.16s, background 0.16s;
		&:hover { color: var(--age-danger, var(--color-error)); background: var(--age-err-bg, rgba(220, 53, 69, 0.15)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: -2px; }
	}
}

// --- mobile: full-width rectangular row (mobile mockup) ---
@media (max-width: 767px) {
	.pw-chip {
		display: flex;
		width: 100%;
		border-radius: var(--age-radius-sm, 8px);
	}
	.pw-chip__open {
		flex: 1 1 auto;
		gap: 11px;
		padding: 10px 12px;
	}
	.pw-chip__av {
		display: grid;
		place-items: center;
		flex: none;
		width: 34px;
		height: 34px;
		border-radius: 50%;
		font-family: var(--age-mono, monospace);
		font-size: 0.76rem;
		font-weight: 600;
		background: var(--age-surface-2, var(--color-background-dark));
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
	}
	.pw-chip__spons {
		width: 34px;
		height: 34px;
	}
	.pw-chip__email {
		flex: 1 1 auto;
		max-width: none;
		font-size: 0.88rem;
	}
	.pw-chip__remove { width: 48px; }
}

@keyframes pw-chip-in {
	from { opacity: 0; transform: translateY(4px); }
	to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
	.pw-chip { animation: none; }
}
</style>
