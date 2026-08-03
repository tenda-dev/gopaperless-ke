<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<button
		type="button"
		role="switch"
		class="gp-toggle"
		:class="{ 'gp-toggle--on': modelValue, 'gp-toggle--disabled': disabled }"
		:aria-checked="modelValue ? 'true' : 'false'"
		:aria-label="label"
		:disabled="disabled"
		@click="toggle">
		<span class="gp-toggle__knob" />
	</button>
</template>

<script setup lang="ts">
defineOptions({
	name: 'SignatureToggle',
})

const props = withDefaults(defineProps<{
	modelValue: boolean
	disabled?: boolean
	label?: string
}>(), {
	disabled: false,
	label: '',
})

const emit = defineEmits<{
	(event: 'update:modelValue', value: boolean): void
}>()

function toggle() {
	if (props.disabled) {
		return
	}
	emit('update:modelValue', !props.modelValue)
}
</script>

<style lang="scss" scoped>
.gp-toggle {
	position: relative;
	flex: 0 0 auto;
	box-sizing: border-box;
	width: 48px;
	height: 28px;
	// Nextcloud core forces `min-height` on <button>; pin it so the pill
	// keeps its fixed height instead of stretching to the flex row height.
	min-height: 28px !important;
	padding: 0;
	margin: 0;
	border: none;
	border-radius: 999px;
	background: #c4c9d0;
	cursor: pointer;
	transition: background 180ms cubic-bezier(0.22, 1, 0.36, 1);

	&__knob {
		position: absolute;
		top: 3px;
		inset-inline-start: 3px;
		width: 22px;
		height: 22px;
		border-radius: 50%;
		background: #fff;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.24);
		transition: transform 180ms cubic-bezier(0.22, 1, 0.36, 1);
	}

	&--on {
		background: #16a34a;

		.gp-toggle__knob {
			transform: translateX(20px);
		}
	}

	&:focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.28);
	}

	&--disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
}
</style>
