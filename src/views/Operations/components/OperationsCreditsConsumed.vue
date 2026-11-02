<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-cons">
		<div class="ops-cons__head">
			<!-- Dated by signing time: no consumed_at exists, so this is a proxy, not an exact consumption date. -->
			<OperationsProvenance :confirmed="false" />
			<span class="ops-cons__title">{{ t('libresign', 'Credit consumed') }}</span>
		</div>

		<p v-if="data === null" class="ops-cons__none">
			{{ t('libresign', 'Credit consumption data is unavailable') }}
		</p>

		<template v-else>
			<div class="ops-cons__total">
				<span class="ops-cons__number">{{ formatInt(data.total) }}</span>
				<span class="ops-cons__unit">{{ t('libresign', 'consumed this period') }}</span>
			</div>

			<ul v-if="rows.length" class="ops-cons__list">
				<li v-for="r in rows" :key="r.key">
					<span>{{ r.label }}</span><b>{{ formatInt(r.count) }}</b>
				</li>
			</ul>
			<p v-else class="ops-cons__none">
				{{ t('libresign', 'None') }}
			</p>

			<p class="ops-cons__note">
				<OperationsIcon :path="mdiInformationOutline" size="xs" />
				<span>{{ t('libresign', 'Each completed signing counts as one credit consumed, dated by the signing date. Unlimited credit entitlements are counted as one credit per signing.') }}</span>
			</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiInformationOutline } from '@mdi/js'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { CreditConsumptionMetric, Unavailable } from '../types.ts'
import { formatInt } from '../utils/format.ts'
import { poolRows } from '../utils/funding.ts'

defineOptions({ name: 'OperationsCreditsConsumed' })

const props = defineProps<{ metric: CreditConsumptionMetric | Unavailable }>()

/** null = the server marked the metric unavailable; render that, never a fabricated 0. */
const data = computed<CreditConsumptionMetric | null>(() =>
	(props.metric as Unavailable).available === false ? null : props.metric as CreditConsumptionMetric,
)

// Credit-grained, unlike the document-grained pools in the Funding mix; the two are not comparable.
const rows = computed(() => data.value ? poolRows(data.value.byType) : [])
</script>

<style scoped lang="scss">
.ops-cons {
	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__title { font-size: 12.5px; font-weight: 600; }

	&__total {
		display: flex;
		align-items: baseline;
		gap: 8px;
	}

	&__number {
		font-family: var(--ops-font-display);
		font-size: 28px;
		font-weight: 600;
		line-height: 1;
		letter-spacing: -0.025em;
		font-variant-numeric: tabular-nums;
	}

	&__unit { font-size: 12px; color: var(--ops-fg-muted); }

	&__list {
		margin: 12px 0 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			justify-content: space-between;
			gap: 8px;
			padding: 6px 0;
			border-top: 1px solid var(--ops-border);
			font-size: 12.5px;
			color: var(--ops-fg-muted);

			b { color: var(--ops-fg); font-family: var(--ops-font-mono); }
		}
	}

	&__none { margin: 6px 0 0; font-size: 12px; color: var(--ops-fg-faint); }

	&__note {
		display: flex;
		gap: 9px;
		margin: 12px 0 0;
		font-size: 11.5px;
		line-height: 1.5;
		color: var(--ops-fg-muted);

		:deep(.ops-icon) { flex: none; margin-top: 2px; }
	}
}
</style>
