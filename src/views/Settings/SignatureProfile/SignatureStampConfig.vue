<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-stamp">
		<p class="gp-stamp__lead">
			{{ t('libresign', 'Override the global stamp for this customer. Leave fields empty to keep the shared setting. Only the “Signer name” and “Description only” modes replace a signer’s own signature.') }}
		</p>

		<RenderModeSelector
			:model-value="modelValue.renderMode"
			:globals="globals"
			@update:model-value="update('renderMode', $event)" />

		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Signature text template') }}</label>
			<textarea
				class="gp-stamp__control gp-stamp__textarea"
				rows="3"
				:value="modelValue.textTemplate"
				:placeholder="globals.textTemplate || t('libresign', 'Inherit the global template')"
				@input="update('textTemplate', ($event.target as HTMLTextAreaElement).value)" />
			<span class="gp-stamp__hint">{{ t('libresign', 'Leave empty to inherit the global template.') }}</span>
		</div>

		<DimensionInputs
			:model-value="modelValue"
			:globals="globals"
			@update:model-value="emit('update:modelValue', $event)" />
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import RenderModeSelector from './RenderModeSelector.vue'
import DimensionInputs from './DimensionInputs.vue'

defineOptions({
	name: 'SignatureStampConfig',
})

const props = defineProps<{
	modelValue: StampDraft
	globals: StampGlobals
}>()

const emit = defineEmits<{
	'update:modelValue': [value: StampDraft]
}>()

export type StampDraft = {
	enabled: boolean
	renderMode: string
	textTemplate: string
	signatureFontSize: string
	templateFontSize: string
	width: string
	height: string
}

export type StampGlobals = {
	renderMode: string
	textTemplate: string
	signatureFontSize: number
	templateFontSize: number
	width: number
	height: number
}

function update(key: keyof StampDraft, value: string | boolean) {
	emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<style lang="scss" scoped>
.gp-stamp {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin: 4px 0 8px 28px;
	padding: 16px;
	background: #f8fafc;
	border: 1px solid #eef0f3;
	border-radius: 12px;

	&__lead {
		margin: 0;
		font-size: 13px;
		line-height: 1.45;
		color: #6b7280;
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

	&__textarea {
		resize: vertical;
		font-family: var(--font-face, monospace);
		line-height: 1.4;
	}

	&__hint {
		font-size: 12px;
		color: #9ca3af;
	}
}
</style>
