<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Presentational action card for the public workflow.
  Mirrors the authenticated workflow's action-card pattern without reusing
  WorkflowActions, which depends on authenticated signing state.

  Prop-driven and independent of workflow stores and authentication.
  The parent owns the actions and workflow state; this component only
  presents the supplied content and emits user interactions.
-->
<template>
	<div class="pw-actioncard" :class="{ 'is-ready': ready }">
		<div class="pw-actioncard__icon">
			<NcIconSvgWrapper :path="icon" :size="22" />
		</div>
		<div class="pw-actioncard__content">
			<h3 class="pw-actioncard__title">{{ title }}</h3>
			<p class="pw-actioncard__desc">{{ description }}</p>
			<p v-if="helper" class="pw-actioncard__helper">{{ helper }}</p>
		</div>
		<div class="pw-actioncard__buttons">
			<button type="button"
				class="pw-btn pw-btn--primary"
				:class="{ 'pw-actioncard__button--glow': !disabled }"
				:disabled="disabled"
				@click="$emit('primary')">
				{{ primaryLabel }}
			</button>
			<button v-if="secondaryLabel" type="button" class="pw-btn pw-actioncard__secondary" @click="$emit('secondary')">
				{{ secondaryLabel }}
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
withDefaults(defineProps<{
	icon: string
	title: string
	description: string
	helper?: string
	primaryLabel: string
	secondaryLabel?: string
	disabled?: boolean
	/** Mirrors WorkflowActions' `--ready` state (subtle green border/ring) once the action is actionable. */
	ready?: boolean
}>(), { helper: '', secondaryLabel: '', disabled: false, ready: false })

defineEmits<{ primary: [], secondary: [] }>()
</script>

<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-actioncard {
	@include agtokens.age-tokens;
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 10px;
	padding: 24px;
	border-radius: 14px;
	border: 1px solid var(--age-border, rgba(0, 0, 0, 0.08));
	background: linear-gradient(to bottom, var(--age-surface, #fff), var(--age-surface, #fff));
	box-shadow: 0 1px 2px rgba(13, 17, 22, 0.05);
	transition: border-color 220ms ease, box-shadow 220ms ease, transform 220ms ease;

	&:hover { transform: translateY(-1px); box-shadow: 0 18px 42px rgba(13, 17, 22, 0.06); }

	&.is-ready {
		border-color: var(--age-success-border, rgba(4, 213, 109, 0.18));
		box-shadow: 0 0 0 4px var(--age-success-tint, rgba(4, 213, 109, 0.04));
	}

	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 48px;
		height: 48px;
		border-radius: 14px;
		background: linear-gradient(135deg, var(--age-success-tint, rgba(4, 213, 109, 0.14)), rgba(4, 213, 109, 0.04));
		color: var(--age-success-fg, #0a6636);
	}

	&__content { display: flex; flex-direction: column; gap: 5px; }
	&__title { margin: 0; font-size: 1.02rem; font-weight: 700; line-height: 1.2; color: var(--age-fg, var(--color-main-text)); }
	&__desc { margin: 0; font-size: 0.86rem; line-height: 1.45; color: var(--age-fg-muted, var(--color-text-maxcontrast)); }
	&__helper { margin: 0; font-size: 0.82rem; font-weight: 600; color: var(--age-warning-fg, rgb(161, 92, 0)); }

	&__buttons { display: flex; flex-direction: column; gap: 9px; width: 100%; margin-top: 4px; }
	&__button--glow { box-shadow: 0 10px 26px rgba(4, 213, 109, 0.18); }
	&__secondary {
		background: var(--age-success-tint, rgba(4, 213, 109, 0.1));
		color: var(--age-success-fg, #0a6636);
		border: 1px solid var(--age-success-border, rgba(4, 213, 109, 0.28));
	}
}

// Self-contained button base (scoped styles don't leak between components, so
// this mirrors — rather than relies on — PublicWorkflowNextShell's .pw-btn).
.pw-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	width: 100%;
	border: 1px solid var(--age-border-strong, var(--color-border-dark));
	background: var(--age-surface, var(--color-main-background));
	color: var(--age-fg, var(--color-main-text));
	font: inherit;
	font-weight: 600;
	font-size: 13.5px;
	padding: 11px 16px;
	border-radius: var(--age-radius-sm, 9px);
	cursor: pointer;
	transition: background 0.18s, transform 0.1s;

	&:hover:not(:disabled) { background: var(--age-surface-2, var(--color-background-hover)); }
	&:active:not(:disabled) { transform: translateY(1px); }
	&[disabled] { opacity: 0.45; cursor: not-allowed; }
}

.pw-btn--primary {
	background: var(--age-success, var(--color-primary-element));
	border-color: transparent;
	color: var(--age-on-success, var(--color-primary-element-text));
	&:hover:not(:disabled) { background: var(--age-success-strong, var(--color-primary-element-hover)); }
}
</style>
