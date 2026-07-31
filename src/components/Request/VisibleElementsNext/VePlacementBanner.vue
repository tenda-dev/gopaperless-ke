<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ve-banner" :class="`ve-banner--${mode}`" role="status" aria-live="polite">
		<div class="ve-banner__text">
			<NcIconSvgWrapper :path="icon" :size="18" class="ve-banner__icon" aria-hidden="true" />
			<span>{{ message }}</span>
		</div>
		<div class="ve-banner__actions">
			<template v-if="mode === 'place'">
				<!-- Cancel on pointerdown: the PDF library commits the box on the
				     document's touchend/mouseup, which would otherwise fire before a
				     click handler. Cancelling on pointerdown detaches that listener
				     first, so cancel truly discards instead of dropping a box. -->
				<VeButton variant="tertiary" small
					@pointerdown="$emit('cancel')"
					@click="$emit('cancel')">
					{{ t('libresign', 'Cancel') }}
				</VeButton>
			</template>
			<template v-else-if="mode === 'review'">
				<VeButton variant="tertiary" small @click="$emit('show-all')">
					{{ t('libresign', 'Show all') }}
				</VeButton>
			</template>
			<template v-else-if="mode === 'edit' && showEditToggle">
				<VeButton variant="secondary" small @click="$emit('toggle-edit')">
					{{ t('libresign', 'Done') }}
				</VeButton>
			</template>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiCursorDefaultClickOutline, mdiEyeOutline, mdiGestureTapButton, mdiPencilOutline } from '@mdi/js'
import VeButton from './VeButton.vue'

defineOptions({
	name: 'VePlacementBanner',
})

const props = withDefaults(defineProps<{
	mode: 'browse' | 'review' | 'place' | 'edit'
	activeSignerName?: string
	showEditToggle?: boolean
}>(), {
	activeSignerName: '',
	showEditToggle: true,
})

defineEmits<{
	(event: 'cancel'): void
	(event: 'show-all'): void
	(event: 'toggle-edit'): void
}>()

const message = computed(() => {
	switch (props.mode) {
	case 'place':
		return props.activeSignerName
			? t('libresign', 'Tap where {name} should sign', { name: props.activeSignerName })
			: t('libresign', 'Tap the document to place the signature')
	case 'review':
		return t('libresign', 'Showing {name}', { name: props.activeSignerName })
	case 'edit':
		return t('libresign', 'Drag or resize to adjust')
	case 'browse':
	default:
		return t('libresign', 'Select a signer to review or place')
	}
})

const icon = computed(() => {
	switch (props.mode) {
	case 'place':
		return mdiGestureTapButton
	case 'review':
		return mdiEyeOutline
	case 'edit':
		return mdiPencilOutline
	case 'browse':
	default:
		return mdiCursorDefaultClickOutline
	}
})
</script>

<style lang="scss" scoped>
.ve-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 6px 8px 6px 12px;
	border-radius: 8px;
	background-color: var(--color-background-hover);
	min-height: 40px;
	font-size: 14px;

	&--place {
		background-color: rgba(4, 213, 109, 0.12);
		box-shadow: inset 0 0 0 1px rgba(4, 213, 109, 0.4);
	}

	&__text {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
		color: var(--color-text-maxcontrast);
	}

	&__icon {
		flex-shrink: 0;
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: 2px;
		flex-shrink: 0;
	}
}
</style>
