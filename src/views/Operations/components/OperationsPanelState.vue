<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-state">
		<!-- Loading -->
		<div v-if="status === 'loading'" class="ops-state__loading" aria-busy="true" :aria-label="t('libresign', 'Loading')">
			<div v-for="i in skeletonRows" :key="i" class="ops-state__skel" :style="{ width: i === 1 ? '45%' : '100%' }" />
		</div>

		<!-- Error -->
		<div v-else-if="status === 'error'" class="ops-state__block ops-state__block--error" role="alert">
			<div class="ops-state__icon">
				<OperationsIcon :path="mdiAlertOutline" size="xl" />
			</div>
			<h3>{{ errorTitle }}</h3>
			<p>{{ errorBody }}</p>
			<button v-if="retryable" type="button" class="ops-state__retry" @click="emit('retry')">
				<OperationsIcon :path="mdiRefresh" size="sm" />
				{{ t('libresign', 'Retry') }}
			</button>
		</div>

		<!-- Empty -->
		<div v-else-if="status === 'empty'" class="ops-state__block">
			<div class="ops-state__icon">
				<OperationsIcon :path="mdiInboxOutline" size="xl" />
			</div>
			<h3>{{ t('libresign', 'No activity in this period') }}</h3>
			<p>{{ t('libresign', 'There is nothing to report between these dates. Try a wider range.') }}</p>
		</div>

		<slot v-else />
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiAlertOutline, mdiInboxOutline, mdiRefresh } from '@mdi/js'


import type { PanelStatus } from '../types.ts'

import OperationsIcon from './OperationsIcon.vue'

defineOptions({ name: 'OperationsPanelState' })

const props = withDefaults(defineProps<{
	status: PanelStatus
	error?: string | null
	skeletonRows?: number
}>(), {
	error: null,
	skeletonRows: 3,
})

const emit = defineEmits<{ (e: 'retry'): void }>()

// 403 means the server withdrew access (flag turned off, group removed): retrying can't help.
const retryable = computed(() => props.error !== 'Forbidden' && props.error !== 'range_too_large' && props.error !== 'invalid_range')

const errorTitle = computed(() => {
	switch (props.error) {
	case 'Forbidden':
		return t('libresign', 'You no longer have access')
	case 'range_too_large':
		return t('libresign', 'That range is too long')
	case 'invalid_range':
	case 'invalid_params':
		return t('libresign', 'That date range isn’t valid')
	default:
		return t('libresign', 'Couldn’t load this section')
	}
})

const errorBody = computed(() => {
	switch (props.error) {
	case 'Forbidden':
		return t('libresign', 'Operations analytics are disabled or your access was removed.')
	case 'range_too_large':
		return t('libresign', 'Choose a range of 366 days or fewer.')
	case 'invalid_range':
	case 'invalid_params':
		return t('libresign', 'Check that the start date is on or before the end date.')
	case 'network':
		return t('libresign', 'The server couldn’t be reached. This view is read-only; nothing was changed.')
	default:
		return t('libresign', 'The metrics service didn’t respond. This view is read-only; nothing was changed.')
	}
})
</script>

<style scoped lang="scss">
.ops-state {
	&__loading {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__skel {
		height: 14px;
		border-radius: 5px;
		background: linear-gradient(90deg, var(--ops-sunken), var(--ops-border), var(--ops-sunken));
		background-size: 200% 100%;
		animation: ops-shimmer 1.3s infinite;
	}

	&__block {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 8px;
		min-height: 160px;
		padding: 24px 12px;
		text-align: center;

		h3 {
			margin: 0;
			font-family: var(--ops-font-display);
			font-size: 15px;
			font-weight: 600;
		}

		p {
			margin: 0;
			max-width: 340px;
			font-size: 12.5px;
			color: var(--ops-fg-muted);
		}

		&--error .ops-state__icon {
			background: var(--gp-error-bg, rgba(220, 53, 69, 0.15));
			color: var(--ops-crit);
		}
	}

	&__retry {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		margin-top: 4px;
		padding: 8px 14px;
		border: 1px solid var(--ops-border-strong);
		border-radius: 8px;
		background: var(--ops-surface);
		color: var(--ops-fg);
		font: inherit;
		font-size: 12px;
		font-weight: 600;
		letter-spacing: 0.01em;
		cursor: pointer;
		transition: background var(--ops-ease), border-color var(--ops-ease), transform var(--ops-ease), box-shadow var(--ops-ease);

		&:hover {
			background: var(--ops-sunken);
			transform: translateY(-1px);
		}

		&:active {
			transform: scale(0.98);
		}

		&:focus-visible {
			outline: none;
			box-shadow: var(--ops-focus-ring);
		}
	}

	&__icon {
		display: grid;
		place-items: center;
		width: 46px;
		height: 46px;
		border-radius: 12px;
		background: var(--ops-sunken);
		color: var(--ops-fg-faint);
	}
}

@keyframes ops-shimmer {
	to { background-position: -200% 0; }
}

@media (prefers-reduced-motion: reduce) {
	.ops-state__skel { animation: none; }
}
</style>
