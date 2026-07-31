<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-stamp">
		<p class="gp-stamp__lead">
			{{ t('libresign', 'Override the global stamp for this customer. Leave a field on “Inherit global” or empty to keep the shared setting.') }}
		</p>

		<div class="gp-stamp__field">
			<label class="gp-stamp__label">{{ t('libresign', 'Render mode') }}</label>
			<select
				class="gp-stamp__control"
				:value="modelValue.renderMode"
				@change="update('renderMode', ($event.target as HTMLSelectElement).value)">
				<option value="">
					{{ t('libresign', 'Inherit global ({mode})', { mode: globalRenderModeLabel }) }}
				</option>
				<option v-for="mode in renderModes" :key="mode.value" :value="mode.value">
					{{ mode.label }}
				</option>
			</select>
		</div>

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
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'

defineOptions({
	name: 'SignatureStampConfig',
})

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

const props = defineProps<{
	modelValue: StampDraft
	globals: StampGlobals
}>()

const emit = defineEmits<{
	(event: 'update:modelValue', value: StampDraft): void
}>()

const renderModes = [
	{ value: 'DESCRIPTION_ONLY', label: t('libresign', 'Description only') },
	{ value: 'GRAPHIC_AND_DESCRIPTION', label: t('libresign', 'Signature and description') },
	{ value: 'SIGNAME_AND_DESCRIPTION', label: t('libresign', 'Signer name and description') },
	{ value: 'GRAPHIC_ONLY', label: t('libresign', 'Signature only') },
]

const globalRenderModeLabel = computed(() =>
	renderModes.find((mode) => mode.value === props.globals.renderMode)?.label
	?? (props.globals.renderMode || t('libresign', 'default')))

function update(key: keyof StampDraft, value: string) {
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

	&__grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
	}
}
</style>
