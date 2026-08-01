<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-stamp">
		<p class="gp-stamp__lead">
			{{ t('libresign', 'Override the global stamp for this customer. Leave fields empty to keep the shared setting. Only the “Signer name” and “Description only” modes replace a signer’s own signature.') }}
		</p>

		<fieldset class="gp-stamp__modes">
			<legend class="gp-stamp__label">{{ t('libresign', 'Render mode') }}</legend>
			<label class="gp-stamp__mode">
				<input
					type="radio"
					class="gp-stamp__radio"
					name="stamp-render-mode"
					value=""
					:checked="modelValue.renderMode === ''"
					@change="update('renderMode', '')">
				<span class="gp-stamp__mode-text">
					<span class="gp-stamp__mode-title">{{ t('libresign', 'Inherit global') }}</span>
					<span class="gp-stamp__mode-desc">{{ inheritDescription }}</span>
				</span>
			</label>
			<label v-for="mode in renderModes" :key="mode.value" class="gp-stamp__mode">
				<input
					type="radio"
					class="gp-stamp__radio"
					name="stamp-render-mode"
					:value="mode.value"
					:checked="modelValue.renderMode === mode.value"
					@change="update('renderMode', mode.value)">
				<span class="gp-stamp__mode-text">
					<span class="gp-stamp__mode-title">{{ mode.label }}</span>
					<span
						class="gp-stamp__mode-desc"
						:class="{ 'gp-stamp__mode-desc--warn': mode.warn }">
						{{ mode.description }}
					</span>
				</span>
			</label>
		</fieldset>

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
	{
		value: 'GRAPHIC_AND_DESCRIPTION',
		label: t('libresign', 'Signature and description'),
		description: t('libresign', "Recommended. Shows the signer's own signature. (the one they draw, type, or upload), next to the description text. NEVER replaces a personal signature."),
		warn: false,
	},
	{
		value: 'GRAPHIC_ONLY',
		label: t('libresign', 'Signature only'),
		description: t('libresign', "The signer's own signature on its own, with no description text."),
		warn: false,
	},
	{
		value: 'SIGNAME_AND_DESCRIPTION',
		label: t('libresign', 'Signer name and description'),
		description: t('libresign', "Replaces the signer's own signature with their name printed as text, shown next to the description."),
		warn: true,
	},
	{
		value: 'DESCRIPTION_ONLY',
		label: t('libresign', 'Description only'),
		description: t('libresign', 'Replaces the signer’s own signature with description text only, no signature graphic at all.'),
		warn: true,
	},
]

const globalRenderModeLabel = computed(() =>
	renderModes.find((mode) => mode.value === props.globals.renderMode)?.label
	?? (props.globals.renderMode || t('libresign', 'default')))

const inheritDescription = computed(() =>
	t('libresign', 'Use the global stamp setting (currently: {mode}).', { mode: globalRenderModeLabel.value }))

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

	&__modes {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		border: none;

		.gp-stamp__label {
			padding: 0;
			margin-bottom: 4px;
		}
	}

	&__mode {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 10px 12px;
		border: 1px solid #d7dce3;
		border-radius: 10px;
		background: #fff;
		cursor: pointer;
		transition: border-color 140ms ease, background 140ms ease;

		&:hover {
			border-color: #16a34a;
		}

		&:has(.gp-stamp__radio:checked) {
			border-color: #16a34a;
			background: #f0fdf4;
		}
	}

	&__radio {
		flex: 0 0 auto;
		width: 16px;
		height: 16px;
		min-height: 0;
		margin: 2px 0 0;
		accent-color: #16a34a;
		cursor: pointer;
	}

	&__mode-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__mode-title {
		font-size: 14px;
		font-weight: 600;
		color: #1e293b;
	}

	&__mode-desc {
		font-size: 12.5px;
		line-height: 1.4;
		color: #6b7280;

		&--warn {
			color: #b45309;
		}
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
