<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg class="ops-icon"
		:class="size === 'md' ? '' : `ops-icon--${size}`"
		viewBox="0 0 24 24"
		aria-hidden="true">
		<path :d="path" />
	</svg>
</template>

<script setup lang="ts">
/**
 * Plain inline @mdi/js icon, mirroring the `<svg class="icon"><path :d="…"/></svg>`
 * markup PaymentStep uses. Deliberately not NcIconSvgWrapper. Colour comes from
 * `currentColor`, so theming is inherited from the parent.
 */
defineOptions({ name: 'OperationsIcon' })

withDefaults(defineProps<{
	path: string
	size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
}>(), {
	size: 'md',
})
</script>

<style scoped lang="scss">
.ops-icon {
	width: 16px;
	height: 16px;
	fill: currentColor;
	flex: none;

	&--xs { width: 12px; height: 12px; }
	&--sm { width: 15px; height: 15px; }
	&--lg { width: 18px; height: 18px; }
	&--xl { width: 24px; height: 24px; }
}
</style>
