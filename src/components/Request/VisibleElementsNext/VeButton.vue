<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  VeButton — the editor's own button, so we control size, weight and colour
  rather than inheriting the heavier Nextcloud button styling. Primary uses the
  brand green (#04D56D).
-->
<template>
	<button
		:type="type"
		class="ve-btn"
		:class="[`ve-btn--${variant}`, { 've-btn--wide': wide, 've-btn--small': small }]"
		:disabled="disabled"
		@click="$emit('click', $event)">
		<span v-if="$slots.icon" class="ve-btn__icon">
			<slot name="icon" />
		</span>
		<span class="ve-btn__label"><slot /></span>
	</button>
</template>

<script setup lang="ts">
defineOptions({
	name: 'VeButton',
})

withDefaults(defineProps<{
	variant?: 'primary' | 'secondary' | 'tertiary'
	type?: 'button' | 'submit'
	wide?: boolean
	small?: boolean
	disabled?: boolean
}>(), {
	variant: 'secondary',
	type: 'button',
	wide: false,
	small: false,
	disabled: false,
})

defineEmits<{
	(event: 'click', payload: MouseEvent): void
}>()
</script>

<style lang="scss" scoped>
.ve-btn {
	--ve-green: #04d56d;
	--ve-green-hover: #04c063;
	--ve-green-active: #03a957;

	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	font: inherit;
	font-weight: 600;
	font-size: 14px;
	line-height: 1;
	padding: 9px 14px;
	border-radius: 8px;
	border: 1px solid transparent;
	cursor: pointer;
	white-space: nowrap;
	transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;

	&--small {
		font-size: 13px;
		padding: 6px 10px;
		border-radius: 7px;
	}

	&--wide {
		width: 100%;
	}

	&__icon {
		display: inline-flex;
		align-items: center;
	}

	// Primary — brand green
	&--primary {
		background-color: var(--ve-green);
		color: #ffffff;

		&:hover:not(:disabled) {
			background-color: var(--ve-green-hover);
		}

		&:active:not(:disabled) {
			background-color: var(--ve-green-active);
		}
	}

	// Secondary — outlined, neutral
	&--secondary {
		background-color: transparent;
		color: var(--color-main-text);
		border-color: var(--color-border-dark, var(--color-border));

		&:hover:not(:disabled) {
			background-color: var(--color-background-hover);
		}
	}

	// Tertiary — text only
	&--tertiary {
		background-color: transparent;
		color: var(--color-main-text);

		&:hover:not(:disabled) {
			background-color: var(--color-background-hover);
		}
	}

	&:focus-visible {
		outline: 2px solid var(--ve-green);
		outline-offset: 2px;
	}

	&:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
}
</style>
