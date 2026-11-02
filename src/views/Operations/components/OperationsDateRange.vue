<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-range">
		<div class="ops-range__seg" role="group" :aria-label="t('libresign', 'Period')">
			<span class="ops-range__ind" :style="{ transform: `translateX(${activeIndex * 100}%)` }" aria-hidden="true" />
			<button v-for="opt in options"
				:key="opt.value"
				type="button"
				class="ops-range__btn"
				:aria-pressed="preset === opt.value"
				@click="onSelect(opt.value)">
				{{ opt.label }}
			</button>
		</div>

		<div v-if="preset === 'custom'" class="ops-range__custom">
			<label class="ops-range__field">
				<span>{{ t('libresign', 'From') }}</span>
				<input v-model="draftFrom" type="date" :max="draftTo || undefined" @change="commit">
			</label>
			<label class="ops-range__field">
				<span>{{ t('libresign', 'To') }}</span>
				<input v-model="draftTo" type="date" :min="draftFrom || undefined" @change="commit">
			</label>
			<p v-if="error" class="ops-range__error" role="alert">
				{{ error === 'range_too_large' ? t('libresign', 'Choose a range of 366 days or fewer.') : t('libresign', 'The start date must be on or before the end date.') }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { t } from '@nextcloud/l10n'

import type { RangePreset } from '../types.ts'

defineOptions({ name: 'OperationsDateRange' })

const props = defineProps<{
	preset: RangePreset
	customFrom: string
	customTo: string
	error: string | null
}>()

const emit = defineEmits<{
	(e: 'preset', value: RangePreset): void
	(e: 'custom', payload: { from: string, to: string }): void
}>()

const options: Array<{ value: RangePreset, label: string }> = [
	{ value: '7', label: t('libresign', '7d') },
	{ value: '30', label: t('libresign', '30d') },
	{ value: '90', label: t('libresign', '90d') },
	{ value: 'custom', label: t('libresign', 'Custom') },
]

const activeIndex = computed(() => Math.max(0, options.findIndex((o) => o.value === props.preset)))

const draftFrom = ref(props.customFrom)
const draftTo = ref(props.customTo)
watch(() => [props.customFrom, props.customTo], ([from, to]) => {
	draftFrom.value = from
	draftTo.value = to
})

function onSelect(value: RangePreset) {
	if (value === 'custom') {
		emit('custom', { from: draftFrom.value, to: draftTo.value })
	} else {
		emit('preset', value)
	}
}

function commit() {
	emit('custom', { from: draftFrom.value, to: draftTo.value })
}
</script>

<style scoped lang="scss">
.ops-range {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;

	&__seg {
		position: relative;
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		padding: 4px;
		background: var(--ops-sunken);
		border-radius: var(--ops-radius-sm);
	}

	// Sliding indicator, as in the payment method switch.
	&__ind {
		position: absolute;
		top: 4px;
		bottom: 4px;
		left: 4px;
		width: calc((100% - 8px) / 4);
		background: var(--ops-surface);
		border-radius: var(--ops-radius-xs);
		box-shadow: var(--ops-shadow-sm);
		transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__btn {
		position: relative;
		z-index: 1;
		min-height: 32px;
		padding: 4px 14px;
		border: 0;
		border-radius: var(--ops-radius-xs);
		background: transparent;
		color: var(--ops-fg-muted);
		font: inherit;
		font-size: 13px;
		font-weight: 500;
		cursor: pointer;
		transition: color var(--ops-ease);

		&[aria-pressed="true"] {
			color: var(--ops-fg);
			font-weight: 600;
		}

		&:focus-visible {
			outline: none;
			box-shadow: var(--ops-focus-ring);
		}
	}

	&__custom {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 10px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: 0.05em;
		text-transform: uppercase;
		color: var(--ops-fg-faint);

		input {
			margin: 0;
			min-height: 36px;
			border: 1.5px solid var(--ops-border-strong);
			border-radius: var(--ops-radius-xs);
			background: var(--ops-surface);
			color: var(--ops-fg);
			text-transform: none;
			letter-spacing: normal;
			transition: border-color var(--ops-ease), box-shadow var(--ops-ease);

			&:focus {
				outline: none;
				border-color: var(--ops-green);
				box-shadow: 0 0 0 4px var(--ops-green-wash);
			}
		}
	}

	&__error {
		flex-basis: 100%;
		margin: 0;
		font-size: 12px;
		color: var(--ops-crit);
	}
}

@media (prefers-reduced-motion: reduce) {
	.ops-range__ind { transition: none; }
}

@media (max-width: 768px) {
	.ops-range {
		width: 100%;

		&__seg { width: 100%; }

		&__btn {
			min-height: 44px; // touch target
			padding: 4px 6px;
		}

		&__custom { width: 100%; }
		&__field { flex: 1; }
	}
}
</style>
