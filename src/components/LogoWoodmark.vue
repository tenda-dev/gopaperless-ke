<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="logo-wordmark"
		:style="{ '--logo-wordmark-size': `${fontSize}px` }"
	>
		<div class="logo-wordmark__name">
			{{ name }}
		</div>

		<div class="logo-wordmark__by">
			<span>with</span>
			<img
				class="logo-wordmark__by-logo"
				:src="TendaLogo"
				alt="Tendaworld"
			>
		</div>
	</div>
</template>

<script setup lang="ts">
import TendaLogo from '../../img/tenda-logo-green-updated.png'

defineOptions({ name: 'LogoWordmark' })

withDefaults(defineProps<{
	name?: string
	fontSize?: number
}>(), {
	name: 'GoPaperless',
	fontSize: 24,
})
</script>

<style scoped lang="scss">
.logo-wordmark {
	display: flex;
	flex-direction: column;
	gap: 2px;

	&__name {
		display: flex;
		align-items: center;
		height: var(--logo-wordmark-size);

		font-size: var(--logo-wordmark-size);
		font-weight: 700;
		line-height: 1;
		letter-spacing: -.01em;
		white-space: nowrap;
	}

	&__by {
		display: inline-flex;
		align-items: center;
		gap: 5px;

		font-family: 'Space Grotesk', ui-monospace, 'SFMono-Regular', menlo, monospace;
		font-size: 12px !important;
		letter-spacing: .02em;
		color: var(--faint, #9aa3af);
	}

	&__by-logo {
		display: block;
		width: auto;
		height: 13px;
		margin-top: -2px;
	}
}
</style>
