<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div v-if="filteredTotal > 0" class="tpager">
		<span class="tpager__count">
			{{ t('libresign', '{from}–{to} of {total}', { from: rangeStart, to: rangeEnd, total: filteredTotal }) }}
		</span>
		<div class="tpager__controls">
			<label class="tpager__size">
				<span>{{ t('libresign', 'Rows') }}</span>
				<select :value="pageSize" @change="onSize">
					<option v-for="s in sizes" :key="s" :value="s">{{ s }}</option>
				</select>
			</label>
			<div class="tpager__nav">
				<button type="button" class="tpager__btn" :disabled="page <= 1" :aria-label="t('libresign', 'Previous page')" @click="$emit('update:page', page - 1)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41,16.58L10.83,12L15.41,7.41L14,6L8,12L14,18L15.41,16.58Z" /></svg>
				</button>
				<span class="tpager__pos">{{ page }} / {{ pageCount }}</span>
				<button type="button" class="tpager__btn" :disabled="page >= pageCount" :aria-label="t('libresign', 'Next page')" @click="$emit('update:page', page + 1)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z" /></svg>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

defineProps<{
	page: number
	pageCount: number
	pageSize: number
	filteredTotal: number
	rangeStart: number
	rangeEnd: number
}>()

const emit = defineEmits<{
	(e: 'update:page', value: number): void
	(e: 'update:pageSize', value: number): void
}>()

const sizes = [10, 25, 50]

function onSize(event: Event) {
	emit('update:pageSize', Number((event.target as HTMLSelectElement).value))
}
</script>

<style scoped lang="scss">
.tpager {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	padding-top: 12px;

	&__count {
		font-size: 12px;
		color: var(--age-fg-muted);
		font-variant-numeric: tabular-nums;
	}

	&__controls {
		display: flex;
		align-items: center;
		gap: 16px;
	}

	&__size {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		color: var(--age-fg-muted);

		select {
			border: 1px solid var(--age-border-strong);
			border-radius: var(--age-radius-sm);
			background: var(--age-surface);
			color: var(--age-fg);
			padding: 4px 8px;
			font-size: 12px;
			cursor: pointer;

			&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 1px; }
		}
	}

	&__nav {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 12px;
		color: var(--age-fg-muted);
		font-variant-numeric: tabular-nums;
	}

	&__pos { min-width: 46px; text-align: center; }

	&__btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 30px;
		height: 30px;
		padding: 0;
		border: 1px solid var(--age-border-strong);
		border-radius: var(--age-radius-sm);
		background: var(--age-surface);
		color: var(--age-fg);
		cursor: pointer;

		svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
			display: block;
		}

		&:hover:not(:disabled) { background: var(--age-surface-2); }
		&:disabled { opacity: 0.4; cursor: not-allowed; }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 1px; }
	}
}
</style>
