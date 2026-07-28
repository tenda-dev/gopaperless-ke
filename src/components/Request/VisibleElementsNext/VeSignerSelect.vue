<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  A signer row/chip. Tapping the main body ARMS placement for that signer; the
  eye button toggles review (isolate that signer's boxes).

  Layouts:
   - card  — desktop sidebar (bordered card).
   - chip  — mobile peek strip (compact, short name).
   - row   — mobile expanded sheet (divider-separated, left colour accent that
             ties the row to its box on the page; red accent when overlapping).
-->
<template>
	<div
		class="ve-signer"
		:class="[`ve-signer--${layout}`, {
			've-signer--reviewing': reviewing,
			've-signer--warn': showWarn,
			've-signer--disabled': disabled,
		}]"
		:style="rootStyle">
		<button
			type="button"
			class="ve-signer__main"
			:aria-label="selectAriaLabel"
			:disabled="disabled"
			@click="onSelect">
			<span class="ve-signer__avatar" :style="avatarStyle" aria-hidden="true">{{ initials }}</span>
			<span class="ve-signer__body">
				<span class="ve-signer__name">{{ nameText }}</span>
				<span v-if="layout !== 'chip'" class="ve-signer__status-line" :class="{ 've-signer__status-line--warn': showWarn }">
					<NcIconSvgWrapper v-if="showWarn" :path="mdiAlertOutline" :size="14" aria-hidden="true" />
					<span>{{ statusText }}</span>
				</span>
			</span>
		</button>

		<div class="ve-signer__aside">
			<button
				v-if="placedCount > 0 && showReview"
				type="button"
				class="ve-signer__review"
				:class="{ 've-signer__review--on': reviewing }"
				:aria-pressed="reviewing"
				:aria-label="reviewAriaLabel"
				:title="reviewAriaLabel"
				@click="onReview">
				<NcIconSvgWrapper :path="reviewing ? mdiEye : mdiEyeOutline" :size="18" />
			</button>
			<span v-if="layout !== 'row'" class="ve-signer__indicator" aria-hidden="true">
				<span v-if="placedCount > 0" class="ve-signer__dot" :style="{ backgroundColor: palette.base }" />
				<span v-else class="ve-signer__ring" :class="{ 've-signer__ring--warn': showWarn }" />
			</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiAlertOutline, mdiEye, mdiEyeOutline } from '@mdi/js'
import type { Palette } from './composables/useSignerColors'
import type { SignerSummaryRecord } from '../../../types/index'

defineOptions({
	name: 'VeSignerSelect',
})

const props = withDefaults(defineProps<{
	signer: SignerSummaryRecord
	palette: Palette
	placedCount?: number
	/** This signer is currently being reviewed/isolated. */
	reviewing?: boolean
	disabled?: boolean
	/** Show the review (eye) control. */
	showReview?: boolean
	/** Emphasise empty in amber (desktop card only; some other signer placed). */
	emphasizeEmpty?: boolean
	/** Row belongs to an overlapping pair → red accent instead of signer colour. */
	overlapping?: boolean
	layout?: 'card' | 'chip' | 'row'
}>(), {
	placedCount: 0,
	reviewing: false,
	disabled: false,
	showReview: true,
	emphasizeEmpty: false,
	overlapping: false,
	layout: 'card',
})

const emit = defineEmits<{
	(event: 'select', signer: SignerSummaryRecord): void
	(event: 'review', signer: SignerSummaryRecord): void
}>()

const displayName = computed(() => props.signer.displayName || props.signer.email || t('libresign', 'Unnamed signer'))

// Chips show a short label (first name, or the local part of an email).
const shortName = computed(() => {
	return displayName.value
})

const nameText = computed(() => (props.layout === 'chip' ? shortName.value : displayName.value))

const initials = computed(() => {
	const source = props.signer.displayName || props.signer.email || '?'
	const parts = source.trim().split(/\s+/).filter(Boolean)
	if (parts.length === 0) {
		return '?'
	}
	if (parts.length === 1) {
		return parts[0].slice(0, 2).toUpperCase()
	}
	return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

const showWarn = computed(() => props.placedCount === 0 && props.emphasizeEmpty)

const statusText = computed(() => {
	if (props.placedCount > 0) {
		return n('libresign', '%n position', '%n positions', props.placedCount)
	}
	return t('libresign', 'no position yet')
})

const accentColor = computed(() => (props.overlapping ? 'var(--color-error, #c6533f)' : props.palette.base))

const rootStyle = computed(() => {
	const style: Record<string, string> = {}
	if (props.layout === 'row') {
		style.borderLeftColor = accentColor.value
	}
	if (props.reviewing) {
		style.borderColor = props.palette.base
		style.boxShadow = `inset 0 0 0 1px ${props.palette.base}`
	}
	return style
})

const avatarStyle = computed(() => ({
	backgroundColor: props.palette.base,
	color: props.palette.contrast,
}))

const selectAriaLabel = computed(() => t('libresign', 'Place a signature position for {name}', { name: displayName.value }))
const reviewAriaLabel = computed(() => (props.reviewing
	? t('libresign', 'Stop reviewing {name}', { name: displayName.value })
	: t('libresign', 'Review {name}’s positions', { name: displayName.value })))

function onSelect() {
	if (props.disabled) {
		return
	}
	emit('select', props.signer)
}

function onReview() {
	emit('review', props.signer)
}
</script>

<style lang="scss" scoped>
.ve-signer {
	display: flex;
	align-items: center;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-main-background);
	overflow: hidden;
	transition: border-color 120ms ease, background-color 120ms ease;

	// Neutral gray hover — not the theme's green-tinted --color-background-hover.
	&:hover:not(.ve-signer--disabled) {
		background-color: var(--color-background-dark);
	}

	&--chip {
		width: auto;
		flex-shrink: 0;
		border-radius: 999px;
	}

	// Divider-separated row (mobile expanded): no card, left colour accent.
	&--row {
		border: 0;
		border-bottom: 1px solid var(--color-border);
		border-left: 3px solid transparent;
		border-radius: 0;
		background-color: transparent;
		padding: 10px 15px;
	}

	&--reviewing {
		background-color: var(--color-background-hover);
	}

	&--warn:not(.ve-signer--reviewing):not(.ve-signer--row) {
		border-color: var(--color-warning, #ba7517);
	}

	&--disabled {
		opacity: 0.5;
	}

	&__main {
		display: flex;
		align-items: center;
		gap: 10px;
		flex: 1;
		min-width: 0;
		border: none;
		background: transparent;
		font: inherit;
		color: var(--color-main-text);
		text-align: start;
		cursor: pointer;
		padding: 10px 8px 10px 12px;
		margin: 0 !important;
		appearance: none;
		-webkit-appearance: none;
		min-height: 0;
		box-shadow: none !important;

		// Neutralise Nextcloud's global button hover/focus chrome.
		&:hover,
		&:focus,
		&:focus-visible,
		&:active {
			background-color: transparent !important;
			box-shadow: none !important;
		}

		&:disabled {
			cursor: not-allowed;
		}
	}

	&--chip &__main {
		padding: 5px 4px 5px 5px;
		gap: 7px;
	}

	&--row &__main {
		padding: 12px 8px 12px 14px;
	}

	&--chip &__avatar {
		width: 28px;
		height: 28px;
		font-size: 12px;
	}

	&--chip &__name {
		font-size: 13px;
		max-width: 120px;
	}

	// Lighter, smaller type for an intentional, elegant read (card + row).
	&--card &__name,
	&--row &__name {
		font-size: 13px;
		font-weight: 500;
	}

	&--card &__status-line,
	&--row &__status-line {
		font-size: 11px;
	}

	&__avatar {
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		width: 34px;
		height: 34px;
		border-radius: 50%;
		font-size: 13px;
		font-weight: 600;
	}

	&__body {
		display: flex;
		flex-direction: column;
		min-width: 0;
		gap: 2px;
	}

	&__name {
		font-weight: 600;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__status-line {
		display: flex;
		align-items: center;
		gap: 4px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;

		&--warn {
			color: var(--color-warning, #ba7517);
		}
	}

	&__aside {
		display: flex;
		align-items: center;
		gap: 4px;
		padding-right: 10px;
		flex-shrink: 0;
	}

	&--chip &__aside {
		padding-right: 8px;
	}

	&__review {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: var(--color-text-maxcontrast);
		cursor: pointer;
		appearance: none;
		-webkit-appearance: none;
		min-height: 0;
		box-shadow: none !important;

		&:hover,
		&:focus,
		&:active {
			background-color: transparent !important;
			box-shadow: none !important;
			color: var(--color-main-text);
		}

		&--on {
			color: var(--color-main-text);
		}
	}

	&__dot {
		width: 12px;
		height: 12px;
		border-radius: 50%;
	}

	&__ring {
		display: inline-block;
		width: 14px;
		height: 14px;
		border-radius: 50%;
		border: 2px solid var(--color-border-dark, #b9b9b9);

		&--warn {
			border-color: var(--color-warning, #ba7517);
		}
	}

		@media screen and (max-width: 512px) {

			.ve-signer {
				&__avatar {
					width: 26px;
					height: 26px;
					font-size: 12px;
				}

				&__main {
					padding: 4px;
				}
			}

		}
}
</style>
