<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-preview">
		<div class="gp-preview__page" aria-hidden="true">
			<!-- Document body placeholder -->
			<div class="gp-preview__body">
				<span v-for="line in bodyLines" :key="line" class="gp-preview__line" :style="{ width: line + '%' }" />
			</div>

			<!-- Signature stamp -->
			<div v-if="stamp" class="gp-preview__stamp" :class="`gp-preview__stamp--${stampLayout}`">
				<div v-if="showStampGraphic" class="gp-preview__stamp-graphic">
					<svg viewBox="0 0 60 24" preserveAspectRatio="none">
						<path d="M2 18 C 10 2, 16 22, 24 12 S 40 2, 48 16 S 56 8, 58 12"
							fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
					</svg>
				</div>
				<div v-if="showStampText" class="gp-preview__stamp-text">
					<span class="gp-preview__line" style="width: 90%" />
					<span class="gp-preview__line" style="width: 70%" />
					<span class="gp-preview__line" style="width: 80%" />
				</div>
			</div>

			<!-- Footer band -->
			<div v-if="footer" class="gp-preview__footer">
				<div v-if="qr" class="gp-preview__qr">
					<span v-for="cell in 9" :key="cell" class="gp-preview__qr-cell" />
				</div>
				<div class="gp-preview__footer-text">
					<span class="gp-preview__line gp-preview__line--light" style="width: 60%" />
					<template v-if="auditInfo">
						<span class="gp-preview__line gp-preview__line--light" style="width: 85%" />
						<span class="gp-preview__line gp-preview__line--light" style="width: 45%" />
					</template>
				</div>
			</div>
		</div>

		<span class="gp-preview__hint">{{ hint }}</span>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'

defineOptions({
	name: 'SignatureProfilePreview',
})

const props = withDefaults(defineProps<{
	footer: boolean
	qr: boolean
	stamp: boolean
	auditInfo: boolean
	renderMode: string
}>(), {
	renderMode: 'GRAPHIC_AND_DESCRIPTION',
})

const bodyLines = [96, 88, 92, 70, 84, 60]

const showStampGraphic = computed(() =>
	props.renderMode === 'GRAPHIC_ONLY'
	|| props.renderMode === 'GRAPHIC_AND_DESCRIPTION'
	|| props.renderMode === 'SIGNAME_AND_DESCRIPTION')

const showStampText = computed(() => props.renderMode !== 'GRAPHIC_ONLY')

const stampLayout = computed(() => {
	if (props.renderMode === 'GRAPHIC_ONLY') {
		return 'graphic'
	}
	if (props.renderMode === 'DESCRIPTION_ONLY') {
		return 'text'
	}
	return 'split'
})

const hint = computed(() => {
	if (!props.footer && !props.stamp) {
		return t('libresign', 'No footer or signature stamp will be rendered.')
	}
	return t('libresign', 'Schematic preview — actual output depends on the document and stamp content.')
})
</script>

<style lang="scss" scoped>
.gp-preview {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__page {
		position: relative;
		width: 100%;
		height: 240px;
		box-sizing: border-box;
		padding: 18px;
		background: #fff;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
		box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
		overflow: hidden;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 9px;
	}

	&__line {
		display: block;
		height: 6px;
		border-radius: 3px;
		background: #e5e8ec;

		&--light {
			height: 5px;
			background: #eaedf1;
		}
	}

	&__stamp {
		position: absolute;
		inset-inline-end: 18px;
		bottom: 64px;
		display: flex;
		align-items: center;
		gap: 8px;
		width: 150px;
		height: 52px;
		padding: 8px;
		box-sizing: border-box;
		border: 1.5px dashed #16a34a;
		border-radius: 8px;
		background: rgba(22, 163, 74, 0.05);

		&--graphic {
			justify-content: center;
		}

		&--text {
			flex-direction: column;
			justify-content: center;
			gap: 5px;
		}
	}

	&__stamp-graphic {
		flex: 0 0 auto;
		width: 56px;
		color: #16a34a;

		svg {
			display: block;
			width: 100%;
			height: 22px;
		}
	}

	&__stamp-text {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 4px;
		min-width: 0;

		.gp-preview__line {
			height: 4px;
			background: #c7ddcf;
		}
	}

	&__footer {
		position: absolute;
		inset-inline: 0;
		bottom: 0;
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 18px;
		background: #f5f8f6;
		border-top: 1px solid #e2e8f0;
	}

	&__qr {
		flex: 0 0 auto;
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		grid-template-rows: repeat(3, 1fr);
		gap: 2px;
		width: 30px;
		height: 30px;
		padding: 3px;
		background: #fff;
		border: 1px solid #cbd5e1;
		border-radius: 4px;
	}

	&__qr-cell {
		border-radius: 1px;
		background: #1e293b;

		&:nth-child(2),
		&:nth-child(4),
		&:nth-child(9) {
			background: transparent;
		}
	}

	&__footer-text {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 5px;
		min-width: 0;
	}

	&__hint {
		font-size: 12px;
		line-height: 1.4;
		color: #9ca3af;
	}
}
</style>
