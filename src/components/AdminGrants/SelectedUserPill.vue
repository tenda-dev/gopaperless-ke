<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="age-pill" role="region" :aria-label="t('libresign', 'Selected user')">
		<span class="age-pill__avatar">{{ initials }}</span>
		<span class="age-pill__who">
			<span class="age-pill__name">{{ user.displayName || user.uid }}</span>
			<span class="age-pill__uid">{{ user.uid }}</span>
		</span>
		<span class="age-pill__div" />
		<button type="button" class="age-pill__btn age-pill__btn--accent" @click="$emit('grant')">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiPlus" /></svg>
			{{ t('libresign', 'Grant credits') }}
		</button>
		<button type="button" class="age-pill__btn age-pill__btn--icon" :aria-label="t('libresign', 'Clear selected user')" @click="$emit('clear')">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiClose" /></svg>
		</button>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiClose, mdiPlus } from '@mdi/js'
import type { AdminUser } from './useAdminEntitlements'

const props = defineProps<{ user: AdminUser }>()
defineEmits<{ (e: 'clear'): void; (e: 'grant'): void }>()

const initials = computed(() => {
	const base = (props.user.displayName || props.user.uid || '?').trim()
	const parts = base.split(/\s+/)
	const first = parts[0]?.[0] ?? ''
	const second = parts.length > 1 ? parts[parts.length - 1][0] : ''
	return (first + second).toUpperCase() || '?'
})
</script>

<style scoped lang="scss">
// Floating dark pill, centered near the top, mirroring FilesNextSelectionPill.
// Sticky (not absolute) so it reserves its own strip and never covers the table
// controls below while still following the scroll.
.age-pill {
	position: sticky;
	top: 10px;
	z-index: 30;
	width: fit-content;
	max-width: 100%;
	margin: 0 auto 18px;
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 5px 7px 5px 10px;
	border-radius: 12px;
	background: #1c1c1e;
	color: #fff;
	box-shadow: 0 12px 34px rgba(0, 0, 0, 0.30);

	&__avatar {
		flex: none;
		width: 28px;
		height: 28px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		font-size: 12px;
		font-weight: 600;
		background: rgba(255, 255, 255, 0.14);
		color: #fff;
	}

	&__who {
		display: flex;
		flex-direction: column;
		min-width: 0;
		padding: 0 6px 0 9px;
		line-height: 1.15;
	}

	&__name {
		font-size: 13px;
		font-weight: 600;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 180px;
	}

	&__uid {
		font-family: var(--age-mono, ui-monospace, monospace);
		font-size: 10.5px;
		color: rgba(255, 255, 255, 0.55);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 180px;
	}

	&__div {
		width: 1px;
		height: 24px;
		margin: 0 4px;
		background: rgba(255, 255, 255, 0.18);
		flex: none;
	}

	&__btn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		height: 34px;
		padding: 0 12px;
		border: none;
		border-radius: 9px;
		background: transparent;
		color: #fff;
		font-size: 13px;
		font-weight: 500;
		cursor: pointer;
		white-space: nowrap;

		svg { width: 17px; height: 17px; fill: currentColor; flex: none; }

		&:hover { background: rgba(255, 255, 255, 0.12); }
		&:focus-visible { outline: 2px solid rgba(255, 255, 255, 0.6); outline-offset: -2px; }

		&--accent {
			background: var(--color-primary-element, #04d56d);
			color: var(--color-primary-element-text, #0b0f0c);
			font-weight: 600;

			&:hover { background: var(--color-primary-element-hover, var(--color-primary-element, #05e87a)); }
		}

		&--icon { padding: 0; width: 34px; justify-content: center; }
	}
}

@media (max-width: 520px) {
	.age-pill {
		width: 100%;
		flex-wrap: wrap;
		justify-content: center;
	}
	.age-pill__who { max-width: 100%; }
}
</style>
