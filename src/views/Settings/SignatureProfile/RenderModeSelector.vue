<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<fieldset class="gp-stamp__modes">
		<legend class="gp-stamp__label">{{ t('libresign', 'Render mode') }}</legend>
		<label class="gp-stamp__mode">
			<input
				type="radio"
				class="gp-stamp__radio"
				name="stamp-render-mode"
				value=""
				:checked="modelValue === ''"
				@change="emit('update:modelValue', '')">
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
				:checked="modelValue === mode.value"
				@change="emit('update:modelValue', mode.value)">
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
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { StampGlobals } from './SignatureStampConfig.vue'

defineOptions({
	name: 'RenderModeSelector',
})

const props = defineProps<{
	modelValue: string
	globals: StampGlobals
}>()

const emit = defineEmits<{
	'update:modelValue': [value: string]
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
</script>

<style lang="scss" scoped>
.gp-stamp {
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
}
</style>
