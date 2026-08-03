<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-appearance__body">
		<div class="gp-appearance__pane gp-appearance__pane--preview">
			<div class="gp-appearance__preview">
				<div class="gp-appearance__preview-head">
					<span class="gp-appearance__label">{{ t('libresign', 'Preview') }}</span>
					<div class="gp-appearance__seg" role="tablist">
						<button
							type="button"
							class="gp-appearance__seg-btn"
							:class="{ 'gp-appearance__seg-btn--active': previewMode === 'document' }"
							@click="previewMode = 'document'">
							{{ t('libresign', 'Document') }}
						</button>
						<button
							type="button"
							class="gp-appearance__seg-btn"
							:class="{ 'gp-appearance__seg-btn--active': previewMode === 'stamp' }"
							@click="previewMode = 'stamp'">
							{{ t('libresign', 'Signature stamp') }}
						</button>
					</div>
				</div>

				<SignatureProfilePreview
					v-if="previewMode === 'document'"
					:footer="modelValue.footer"
					:qr="modelValue.footer && modelValue.qr"
					:stamp="modelValue.stamp.enabled"
					:audit-info="modelValue.footer && modelValue.auditInfo"
					:render-mode="effectiveRenderMode" />
				<SignatureStampPreview
					v-else
					:enabled="modelValue.stamp.enabled"
					:render-mode="effectiveRenderMode"
					:width="effectiveWidth"
					:height="effectiveHeight"
					:signature-font-size="effectiveSignatureFont"
					:template-font-size="effectiveTemplateFont"
					:template-text="effectiveTemplateText"
					:is-override="stampTemplateIsOverride" />
			</div>
		</div>

		<div class="gp-appearance__pane gp-appearance__pane--controls">
			<div class="gp-appearance__options-head">
				<span class="gp-appearance__options-title">{{ t('libresign', 'Rendering options') }}</span>
				<button
					type="button"
					class="gp-appearance__reset"
					:disabled="isDraftDefault"
					@click="$emit('reset')">
					{{ t('libresign', 'Reset to default') }}
				</button>
			</div>

			<div class="gp-appearance__rows">
				<SignatureProfileRow
					:model-value="modelValue.footer"
					:title="t('libresign', 'Footer')"
					:description="t('libresign', 'Validation footer band at the bottom of each page')"
					@update:model-value="update({ footer: $event })" />
				<SignatureProfileRow
					:model-value="modelValue.qr"
					indented
					:title="t('libresign', 'QR code')"
					:description="t('libresign', 'Validation QR inside the footer')"
					@update:model-value="update({ qr: $event })" />
				<SignatureProfileRow
					:model-value="modelValue.stamp.enabled"
					:title="t('libresign', 'Signature stamp')"
					:description="t('libresign', 'Visible signature image placed on the page')"
					@update:model-value="updateStamp({ enabled: $event })" />
				<SignatureStampConfig
					v-if="modelValue.stamp.enabled"
					:model-value="modelValue.stamp"
					:globals="stampGlobals"
					@update:model-value="update({ stamp: $event })" />
				<SignatureProfileRow
					:model-value="modelValue.auditInfo"
					:title="t('libresign', 'Audit info')"
					:description="t('libresign', 'Audit and validation details block')"
					@update:model-value="update({ auditInfo: $event })" />
			</div>

			<p class="gp-appearance__meta">
				{{ n('libresign', '%n group has a custom profile', '%n groups have a custom profile', customProfileCount) }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { t, n } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

import SignatureProfileRow from './SignatureProfileRow.vue'
import SignatureProfilePreview from './SignatureProfilePreview.vue'
import SignatureStampPreview from './SignatureStampPreview.vue'
import SignatureStampConfig from './SignatureStampConfig.vue'
import type { Profile } from './composables/useSignatureProfileForm'
import { stampIsDefault } from './composables/useSignatureProfileForm'
import type { StampDraft, StampGlobals } from './SignatureStampConfig.vue'

defineOptions({
	name: 'SignatureProfileEditor',
})

const props = defineProps<{
	modelValue: Profile
	stampGlobals: StampGlobals
	customProfileCount: number
}>()

const emit = defineEmits<{
	'update:modelValue': [value: Profile]
	reset: []
	discard: []
}>()

const previewMode = ref<'document' | 'stamp'>('document')
const stampParsedGlobal = loadState<string>('libresign', 'signature_text_parsed', '')

const effectiveRenderMode = computed(() => props.modelValue.stamp.renderMode || props.stampGlobals.renderMode)
const effectiveWidth = computed(() => Number(props.modelValue.stamp.width || props.stampGlobals.width))
const effectiveHeight = computed(() => Number(props.modelValue.stamp.height || props.stampGlobals.height))
const effectiveSignatureFont = computed(() => Number(props.modelValue.stamp.signatureFontSize || props.stampGlobals.signatureFontSize))
const effectiveTemplateFont = computed(() => Number(props.modelValue.stamp.templateFontSize || props.stampGlobals.templateFontSize))
const stampTemplateIsOverride = computed(() => !!props.modelValue.stamp.textTemplate)
const effectiveTemplateText = computed(() => stampTemplateIsOverride.value ? props.modelValue.stamp.textTemplate : stampParsedGlobal)

const isDraftDefault = computed(() => props.modelValue.footer && props.modelValue.qr && props.modelValue.auditInfo && stampIsDefault(props.modelValue.stamp))

function update(patch: Partial<Profile>) {
	emit('update:modelValue', { ...props.modelValue, ...patch })
}

function updateStamp(patch: Partial<StampDraft>) {
	update({ stamp: { ...props.modelValue.stamp, ...patch } })
}
</script>

<style lang="scss" scoped>
.gp-appearance {
	&__body {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
		gap: 28px;
		align-items: start;
	}

	&__pane {
		display: flex;
		flex-direction: column;
		gap: 16px;
		min-width: 0;
	}

	&__pane--preview {
		position: sticky;
		top: 0;
	}

	&__preview {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__preview-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__label {
		font-size: 14px;
		font-weight: 500;
		color: #475569;
	}

	&__seg {
		display: inline-flex;
		padding: 3px;
		gap: 2px;
		background: #f1f5f9;
		border-radius: 10px;
	}

	&__seg-btn {
		min-height: 0;
		padding: 5px 12px;
		border: none;
		border-radius: 8px;
		background: transparent;
		color: #64748b;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		transition: background 140ms ease, color 140ms ease;

		&--active {
			background: #fff;
			color: #16a34a;
			box-shadow: 0 1px 3px rgba(15, 23, 42, 0.1);
		}
	}

	&__options-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding-bottom: 4px;
		border-bottom: 1px solid #eef0f3;
	}

	&__options-title {
		font-size: 14px;
		font-weight: 500;
		color: #475569;
	}

	&__reset {
		padding: 6px 14px;
		border: 1px solid #d7dce3;
		border-radius: 9px;
		background: #fff;
		color: #1e293b;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		transition: background 160ms ease;

		&:hover:not(:disabled) {
			background: #f5f6f8;
		}

		&:disabled {
			opacity: 0.45;
			cursor: not-allowed;
		}
	}

	&__rows {
		display: flex;
		flex-direction: column;
	}

	&__meta {
		margin: 0;
		font-size: 13px;
		color: #9ca3af;
	}
}

@media (max-width: 720px) {
	.gp-appearance {
		&__body {
			grid-template-columns: 1fr;
			gap: 20px;
		}

		&__pane--preview {
			position: static;
		}
	}
}
</style>
