<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-row" :class="{ 'gp-row--indented': indented }">
		<div class="gp-row__text">
			<span class="gp-row__title">{{ title }}</span>
			<span class="gp-row__description">{{ description }}</span>
		</div>
		<SignatureToggle
			:model-value="modelValue"
			:disabled="disabled"
			:label="title"
			@update:model-value="$emit('update:modelValue', $event)" />
	</div>
</template>

<script setup lang="ts">
import SignatureToggle from './SignatureToggle.vue'

defineOptions({
	name: 'SignatureProfileRow',
})

withDefaults(defineProps<{
	title: string
	description: string
	modelValue: boolean
	indented?: boolean
	disabled?: boolean
}>(), {
	indented: false,
	disabled: false,
})

defineEmits<{
	(event: 'update:modelValue', value: boolean): void
}>()
</script>

<style lang="scss" scoped>
.gp-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 16px 0;
	border-top: 1px solid #eef0f3;

	&--indented {
		padding-inline-start: 28px;
	}

	&__text {
		display: flex;
		flex-direction: column;
		gap: 3px;
		min-width: 0;
	}

	&__title {
		font-size: 15px;
		font-weight: 600;
		color: #1e293b;
	}

	&__description {
		font-size: 13px;
		line-height: 1.4;
		color: #6b7280;
	}
}
</style>
