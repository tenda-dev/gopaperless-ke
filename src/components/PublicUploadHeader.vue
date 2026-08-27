<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<header class="pu__bar">
		<div class="pu__inner pu__bar-row">
			<LogoWoodmark />

			<button type="button" class="pu__signin" @click="$emit('signIn')">
				Have an account? <span>Sign in</span>
			</button>
		</div>
	</header>
</template>

<script setup lang="ts">
import LogoWoodmark from './LogoWoodmark.vue'

defineOptions({ name: 'PublicUploadHeader' })

defineEmits<{
	signIn: []
}>()
</script>

<style scoped lang="scss">
.pu__bar {
	width: 100%;
	border-bottom: 1px solid var(--line-soft);
}

.pu__bar-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 20px var(--pu-pad);
}

.pu__signin {
	border: none;
	background: none;
	padding: 0;
	font: inherit;
	font-size: 13.5px;
	color: var(--slate);
	cursor: pointer;

	span {
		color: var(--brand-strong);
		font-weight: 600;
	}
}
</style>
