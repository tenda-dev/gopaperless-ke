<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="gp-loader"
		:class="`gp-loader--${state}`"
		:role="state === 'error' ? 'alert' : 'status'"
		:aria-live="state === 'error' ? 'assertive' : 'polite'"
		:aria-busy="state === 'loading' ? 'true' : 'false'">
		<template v-if="state === 'loading'">
			<span class="gp-loader__spinner" aria-hidden="true" />
			<span class="gp-loader__sr">{{ loadingLabel }}</span>
		</template>
		<template v-else>
			<span class="gp-loader__message">{{ errorLabel }}</span>
			<button type="button" class="gp-loader__retry" @click="$emit('retry')">
				{{ retryLabel }}
			</button>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'

// Self-contained loading and error UI for async component boundaries.
// Avoids Nextcloud component and theme dependencies so it can render before
// the application styles are available. Color scheme follows system preference.
const props = withDefaults(defineProps<{
	/** Controls whether the loader or recoverable error state is displayed. */
	state?: 'loading' | 'error'
	/** Optional text announced to assistive technology and displayed in the UI. */
	label?: string
}>(), {
	state: 'loading',
	label: '',
})

defineEmits<{ retry: [] }>()

const loadingLabel = computed(() => props.label || t('libresign', 'Loading…'))
const errorLabel = computed(() => props.label || t('libresign', 'Couldn’t load this section.'))
const retryLabel = computed(() => t('libresign', 'Retry'))
</script>

<style scoped>
.gp-loader {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	/* Reserve height so swapping loader -> loaded content doesn't shift layout. */
	min-height: 200px;
	width: 100%;
	padding: 24px;
	box-sizing: border-box;
	font-size: 14px;
	line-height: 1.4;
	text-align: center;
	/* Neutral ink; dark handled below via prefers-color-scheme only. */
	color: #4b5563;
}

.gp-loader__spinner {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2.5px solid #e5e7eb;
	border-top-color: #111827;
	animation: gp-loader-spin 0.7s linear infinite;
}

@keyframes gp-loader-spin {
	to { transform: rotate(360deg); }
}

/* Visually-hidden text announced to assistive tech while loading. */
.gp-loader__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
	border: 0;
}

.gp-loader__message {
	max-width: 32ch;
}

.gp-loader__retry {
	appearance: none;
	border: 1px solid #d1d5db;
	border-radius: 8px;
	background: transparent;
	color: inherit;
	font: inherit;
	padding: 6px 16px;
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease;
}

.gp-loader__retry:hover {
	background: rgba(0, 0, 0, 0.04);
	border-color: #9ca3af;
}

.gp-loader__retry:focus-visible {
	outline: 2px solid #16a34a;
	outline-offset: 2px;
}

@media (prefers-color-scheme: dark) {
	.gp-loader {
		color: #9ca3af;
	}

	.gp-loader__spinner {
		border-color: rgba(255, 255, 255, 0.16);
		border-top-color: #f3f4f6;
	}

	.gp-loader__retry {
		border-color: rgba(255, 255, 255, 0.22);
	}

	.gp-loader__retry:hover {
		background: rgba(255, 255, 255, 0.08);
		border-color: rgba(255, 255, 255, 0.4);
	}
}

@media (prefers-reduced-motion: reduce) {
	.gp-loader__spinner {
		animation: none;
		opacity: 0.6;
	}
}
</style>
