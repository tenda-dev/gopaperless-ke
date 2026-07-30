<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Active-filter chips. Display-only: reads the filters store's active chips and
  - calls each chip's own remove handler (created by the filter dropdowns). No
  - local filter state of its own.
-->
<template>
	<div v-if="chips.length > 0" class="fn-chips">
		<button v-for="(chip, i) in chips" :key="i" type="button" class="fn-chip"
			:aria-label="t('libresign', 'Remove filter')" @click="chip.onclick">
			<span>{{ chip.text }}</span>
			<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiClose" /></svg>
		</button>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'

import { useFiltersStore } from '../../../store/filters.js'

defineOptions({ name: 'FilesNextFilterChips' })

type Chip = { id: string | number, text: string, onclick: () => void }

const filtersStore = useFiltersStore()
const chips = computed(() => filtersStore.activeChips as Chip[])
</script>

<style scoped lang="scss">
.fn-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 0 4px 10px;
}
.fn-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 6px 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 16px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;

	.icon { width: 16px; height: 16px; fill: currentColor; color: var(--color-text-maxcontrast); }

	&:hover {
		background: var(--color-border);
		.icon { color: var(--color-main-text); }
	}
}
</style>
