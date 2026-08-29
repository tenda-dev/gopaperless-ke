<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-stamp__grid">
		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Signature font size') }}</label>
			<input
				class="gp-stamp__control"
				type="number" min="0.1" max="30" step="0.01"
				:value="modelValue.signatureFontSize"
				:placeholder="String(globals.signatureFontSize ?? '')"
				@input="update('signatureFontSize', ($event.target as HTMLInputElement).value)">
		</div>
		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Template font size') }}</label>
			<input
				class="gp-stamp__control"
				type="number" min="0.1" max="30" step="0.01"
				:value="modelValue.templateFontSize"
				:placeholder="String(globals.templateFontSize ?? '')"
				@input="update('templateFontSize', ($event.target as HTMLInputElement).value)">
		</div>
		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Signature width') }}</label>
			<input
				class="gp-stamp__control"
				type="number" min="0.1" max="800" step="0.01"
				:value="modelValue.width"
				:placeholder="String(globals.width ?? '')"
				@input="update('width', ($event.target as HTMLInputElement).value)">
		</div>
		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Signature height') }}</label>
			<input
				class="gp-stamp__control"
				type="number" min="0.1" max="800" step="0.01"
				:value="modelValue.height"
				:placeholder="String(globals.height ?? '')"
				@input="update('height', ($event.target as HTMLInputElement).value)">
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import type { StampDraft, StampGlobals } from './SignatureStampConfig.vue'

defineOptions({
	name: 'DimensionInputs',
})

const props = defineProps<{
	modelValue: StampDraft
	globals: StampGlobals
}>()

const emit = defineEmits<{
	'update:modelValue': [value: StampDraft]
}>()

function update(key: keyof StampDraft, value: string) {
	emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<style lang="scss" scoped>
.gp-stamp {
	&__grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}

	&__label {
		font-size: 13px;
		font-weight: 600;
		color: #475569;
	}

	&__control {
		width: 100%;
		box-sizing: border-box;
		min-height: 0;
		padding: 8px 10px;
		border: 1px solid #d7dce3;
		border-radius: 9px;
		background: #fff;
		color: #1e293b;
		font-size: 14px;

		&:focus {
			outline: none;
			border-color: #16a34a;
			box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
		}
	}
}
</style>
