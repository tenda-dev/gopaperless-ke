<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Public workflow signer entry. Displays signer identity and sponsorship
  status, with responsive layouts for desktop and mobile.

  Editing and removal are exposed as separate accessible actions.
  The component emits events rather than modifying workflow state;
  the parent remains responsible for updating the persisted draft.
-->
<template>
	<div class="pw-chip" :class="{ 'pw-chip--sponsored': showSponsored, 'pw-chip--you': isRequester, 'pw-chip--row': layout === 'row' }">
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
			<span v-if="layout === 'row'" class="pw-chip__info">
				<span class="pw-chip__name">{{ signer.displayName.trim() || signer.email }}</span>
				<span v-if="signer.displayName.trim()" class="pw-chip__email">{{ signer.email }}</span>
			</span>
			<span v-else class="pw-chip__email">{{ signer.email }}</span>
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

const props = withDefaults(defineProps<{
	signer: SignerDraft
	isRequester?: boolean
	/** 'auto' (default): today's compact desktop chip / full-row-on-mobile behaviour, unchanged.
	 *  'row': always the full-width row card (avatar + name/email + status), on every breakpoint —
	 *  mirrors RightSidebar/Workflow/WorkflowSigner.vue's look without importing it (that component
	 *  is coupled to auth signing state). Opt-in; existing callers (PublicWorkflowShell,
	 *  PublicWorkflowNextShell) are unaffected. */
	layout?: 'auto' | 'row'
}>(), { isRequester: false, layout: 'auto' })
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

	// Row-card layout keeps a neutral border throughout (matches the authenticated
	// WorkflowSigner card — status/sponsorship is read from the avatar/text, not a
	// loud card outline); only the compact desktop chip uses border colour as a
	// quick-glance signal.
	&--sponsored:not(&--row) { border-color: var(--age-green-border, var(--color-success)); }
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
	// Remove control: a plain icon button sized to its own hit area — never
	// stretched by the row's height (that was the "big red block on hover" bug:
	// align-items:stretch on .pw-chip plus no explicit height let the hover
	// background fill the full row instead of a small contained circle).
	&__remove {
		flex: none;
		align-self: center;
		width: 28px;
		height: 28px;
		margin-inline-end: 6px;
		border: none;
		border-radius: 50%;
		background: transparent;
		cursor: pointer;
		display: grid;
		place-items: center;
		color: var(--age-fg-faint, var(--color-text-maxcontrast));
		transition: color 0.16s, background 0.16s;
		&:hover { color: var(--age-danger, var(--color-error)); background: var(--age-surface-2, var(--color-background-hover)); }
		&:focus-visible { outline: 2px solid var(--age-focus, var(--color-primary-element)); outline-offset: 1px; }
	}
}

// --- full-width rectangular row ---
// Applies under 767px for the 'auto' layout (today's mobile mockup, unchanged),
// and at EVERY width for the 'row' layout (the row-card variant
// WorkflowSigner.vue inspired — opt-in via PwSignerRow's layout prop).
@media (max-width: 767px) {
	.pw-chip {
		display: flex;
		width: 100%;
		border-radius: var(--age-radius-sm, 8px);

		.pw-chip__open { flex: 1 1 auto; gap: 11px; padding: 10px 12px; }
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
		.pw-chip__spons { width: 34px; height: 34px; }
		.pw-chip__email { flex: 1 1 auto; max-width: none; font-size: 0.88rem; }
		.pw-chip__remove { width: 48px; }
	}
}
.pw-chip--row {
	display: flex;
	align-items: center;
	width: 100%;
	border-radius: var(--age-radius, 14px);
	padding-inline-end: 4px;
	box-shadow: 0 1px 2px rgba(13, 17, 22, 0.04);
}

// --- row-card specifics (avatar + name/email stack) ---
.pw-chip__info {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1 1 auto;
	text-align: start;
}
.pw-chip__name {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--age-fg, var(--color-main-text));
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.pw-chip--row .pw-chip__email {
	font-size: 0.78rem;
	font-weight: 400;
	color: var(--age-fg-muted, var(--color-text-maxcontrast));
	max-width: none;
}
.pw-chip--row {
	.pw-chip__open { flex: 1 1 auto; align-items: center; gap: 12px; padding: 12px 14px; }
	.pw-chip__av {
		display: grid;
		place-items: center;
		flex: none;
		width: 38px;
		height: 38px;
		border-radius: 50%;
		font-family: var(--age-mono, monospace);
		font-size: 0.78rem;
		font-weight: 600;
		background: var(--age-surface-2, var(--color-background-dark));
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
	}
	.pw-chip__spons { width: 38px; height: 38px; }
}

@keyframes pw-chip-in {
	from { opacity: 0; transform: translateY(4px); }
	to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
	.pw-chip { animation: none; }
}
</style>
