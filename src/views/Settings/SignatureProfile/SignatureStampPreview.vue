<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-stamp-preview">
		<div v-if="!enabled" class="gp-stamp-preview__off">
			{{ t('libresign', 'The signature stamp is turned off for this customer.') }}
		</div>

		<template v-else>
			<div class="gp-stamp-preview__stage">
				<div
					class="gp-stamp-preview__box"
					:style="{
						width: scaledWidth + 'px',
						height: scaledHeight + 'px',
						backgroundImage: backgroundUrl ? `url(${backgroundUrl})` : 'none',
					}">
					<div v-if="renderMode !== 'DESCRIPTION_ONLY'" class="gp-stamp-preview__graphic">
						<img
							:src="signatureImageUrl"
							:width="scaledImageWidth"
							:height="scaledImageHeight"
							alt="">
					</div>
					<!-- eslint-disable vue/no-v-html -->
					<div
						v-if="renderMode !== 'GRAPHIC_ONLY'"
						class="gp-stamp-preview__text"
						:style="{ fontSize: scaledTemplateFont + 'px' }"
						v-html="parsedWithLineBreak" />
					<!-- eslint-enable vue/no-v-html -->
				</div>
			</div>

			<span class="gp-stamp-preview__hint">
				{{ isOverride
					? t('libresign', 'Override template shown unparsed; Variables are resolved at signing time.')
					: t('libresign', 'Preview uses the global template and a sample signer.') }}
			</span>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { useIsDarkTheme } from '@nextcloud/vue/composables/useIsDarkTheme'

defineOptions({
	name: 'SignatureStampPreview',
})

const props = withDefaults(defineProps<{
	enabled: boolean
	renderMode: string
	width: number
	height: number
	signatureFontSize: number
	templateFontSize: number
	templateText: string
	isOverride: boolean
}>(), {
	renderMode: 'GRAPHIC_AND_DESCRIPTION',
	width: 350,
	height: 100,
	signatureFontSize: 20,
	templateFontSize: 10,
	templateText: '',
	isOverride: false,
})

// Available horizontal room inside the preview pane for the stamp box.
const MAX_WIDTH = 340

const isDarkTheme = useIsDarkTheme()
const backgroundType = loadState<string>('libresign', 'signature_background_type', '')

const backgroundUrl = computed(() => backgroundType === 'deleted'
	? ''
	: generateOcsUrl('/apps/libresign/api/v1/admin/signature-background'))

const scale = computed(() => Math.min(1, MAX_WIDTH / Math.max(props.width, 1)))
const scaledWidth = computed(() => Math.round(props.width * scale.value))
const scaledHeight = computed(() => Math.round(props.height * scale.value))
const scaledTemplateFont = computed(() => props.templateFontSize * 1.1 * scale.value)

const imageWidth = computed(() => (props.renderMode === 'GRAPHIC_ONLY' || !props.templateText)
	? props.width
	: Math.floor(props.width / 2))
const scaledImageWidth = computed(() => Math.round(imageWidth.value * scale.value))
const scaledImageHeight = computed(() => Math.round(props.height * scale.value))

const signatureImageUrl = computed(() => {
	const text = props.renderMode === 'SIGNAME_AND_DESCRIPTION'
		? (getCurrentUser()?.displayName ?? 'John Doe')
		: t('libresign', 'Signature image here')
	const align = props.renderMode === 'GRAPHIC_AND_DESCRIPTION' ? 'right' : 'center'
	const darkTheme = isDarkTheme ? 1 : 0

	return generateOcsUrl('/apps/libresign/api/v1/admin/signer-name')
		+ `?width=${imageWidth.value}`
		+ `&height=${props.height}`
		+ `&text=${encodeURIComponent(text)}`
		+ `&fontSize=${props.signatureFontSize}`
		+ `&isDarkTheme=${darkTheme}`
		+ `&align=${align}`
})

const parsedWithLineBreak = computed(() => String(props.templateText ?? '').replace(/\n/g, '<br>'))
</script>

<style lang="scss" scoped>
.gp-stamp-preview {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__off {
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: 120px;
		padding: 16px;
		font-size: 14px;
		color: #9ca3af;
		background: #f8fafc;
		border: 1px dashed #e2e8f0;
		border-radius: 10px;
	}

	&__stage {
		display: flex;
		justify-content: center;
		padding: 20px;
		background: repeating-conic-gradient(#f1f3f5 0% 25%, #fff 0% 50%) 50% / 18px 18px;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
	}

	&__box {
		display: flex;
		justify-content: space-between;
		text-align: center;
		background-size: contain;
		background-position: center;
		background-repeat: no-repeat;
		border: 1px solid #cbd5e1;
		background-color: #fff;
	}

	&__graphic {
		display: flex;
		align-items: center;

		img {
			display: block;
			object-fit: contain;
		}
	}

	&__text {
		flex: 1;
		text-align: start;
		line-height: 1;
		overflow-wrap: anywhere;
		overflow: hidden;
		font-family: sans-serif;
		color: #1e293b;
		padding: 2px 4px;
	}

	&__hint {
		font-size: 12px;
		line-height: 1.4;
		color: #9ca3af;
	}
}
</style>
