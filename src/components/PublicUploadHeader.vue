<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<header class="pu__bar">
		<img class="pu__logo"
			:src="theme === 'dark' ? logoOnDark : logoOnLight"
			width="1161"
			height="181"
			alt="GoPaperless">

		<div class="pu__bar-actions">
			<button type="button"
				class="pu__theme"
				:aria-label="themeLabel"
				:title="themeLabel"
				@click="$emit('toggleTheme')">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="theme === 'dark' ? mdiWeatherSunny : mdiWeatherNight" /></svg>
			</button>

			<button type="button" class="pu__signin" @click="$emit('signIn')">
				<span class="pu__signin-ask">Have an account?</span> <b>Sign in</b>
			</button>
		</div>
	</header>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { mdiWeatherNight, mdiWeatherSunny } from '@mdi/js'

// The tendaworld.com logo draws "Paper" in white, which suits the dark theme as
// it is. The ink copy has that white swapped for --ink so it reads on light.
import logoOnDark from '../../img/gopaperless-logo.png'
import logoOnLight from '../../img/gopaperless-logo-ink.png'

defineOptions({ name: 'PublicUploadHeader' })

const props = defineProps<{
	theme: 'light' | 'dark'
}>()

defineEmits<{
	signIn: []
	toggleTheme: []
}>()

const themeLabel = computed(() => props.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode')
</script>

<style scoped lang="scss">
.pu__bar {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: clamp(14px, 1.8vw, 26px) var(--pu-pad);
	border-bottom: 1px solid var(--line);
}

.pu__logo {
	display: block;
	width: auto;
	height: 28px;
}

.pu__bar-actions {
	display: flex;
	align-items: center;
	gap: 14px;
}

// Nextcloud styles every button with a 130px width, a clickable-area min-height
// and a 3px margin. The margin rule outranks one class, so that reset uses two.
.pu__bar-actions .pu__theme,
.pu__bar-actions .pu__signin {
	margin: 0;
}

.pu__theme {
	display: grid;
	place-items: center;
	width: 36px;
	height: 36px;
	min-height: 0;
	padding: 0;
	border: 1px solid var(--line);
	border-radius: 50%;
	background: none;
	color: var(--slate);
	cursor: pointer;
	transition: color .18s, border-color .18s;

	svg {
		width: 18px;
		height: 18px;
	}

	&:hover,
	&:focus-visible {
		border-color: var(--slate);
		color: var(--ink);
	}
}

.pu__signin {
	width: auto;
	min-height: 0;
	padding: 0;
	border: none;
	background: none;
	font: inherit;
	font-size: 14px;
	white-space: nowrap;
	color: var(--slate);
	cursor: pointer;

	b {
		margin-inline-start: 4px;
		font-weight: 600;
		color: var(--brand-strong);
	}
}

@media (max-width: 860px) {
	.pu__logo {
		height: 22px;
	}
}

// Below 400px the logo and the full prompt stop fitting on one line, so the
// button keeps only its action.
@media (max-width: 400px) {
	.pu__signin-ask {
		display: none;
	}
}
</style>
