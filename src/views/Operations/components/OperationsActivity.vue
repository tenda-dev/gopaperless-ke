

<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-activity">
		<div class="ops-card">
			<div class="ops-card__title">
				<OperationsProvenance />
				{{ t('libresign', 'Signing activity') }}
			</div>
			<div class="ops-card__big">
				{{ formatInt(total(data.series.signatures)) }}
			</div>
			<div class="ops-card__sub">
				{{ t('libresign', 'Completed signatures') }}
			</div>
			<OperationsAreaChart :data="data.series.signatures ?? []"
				color="var(--ops-green)"
				:unit="t('libresign', 'signatures')"
				:label="t('libresign', 'Daily completed signatures')" />
		</div>

		<!-- Paid attempts bucketed by when the attempt was created, not when it was confirmed (backend paymentsPaid). -->
		<div class="ops-card">
			<div class="ops-card__title">
				<OperationsProvenance />
				{{ t('libresign', 'Confirmed payments') }}
			</div>
			<div class="ops-card__big">
				{{ formatInt(total(data.series.paymentsPaid)) }}
			</div>
			<div class="ops-card__sub">
				{{ t('libresign', 'Payments confirmed during this period.') }}
			</div>
			<OperationsAreaChart :data="data.series.paymentsPaid ?? []"
				color="var(--ops-info)"
				:unit="t('libresign', 'payments')"
				:label="t('libresign', 'Daily confirmed payments')" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import OperationsAreaChart from './charts/OperationsAreaChart.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { DailyPoint, TimeseriesResponse } from '../types.ts'
import { formatInt } from '../utils/format.ts'

defineOptions({ name: 'OperationsActivity' })

defineProps<{ data: TimeseriesResponse }>()

const total = (series?: DailyPoint[]) => (series ?? []).reduce((sum, p) => sum + p.count, 0)
</script>

<style scoped lang="scss">
.ops-activity {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 12px;
}

.ops-card {
	padding: 16px;
	background: var(--ops-surface);
	border: 1px solid var(--ops-border);
	border-radius: var(--ops-radius);

	&__title {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 7px;
		font-size: 12.5px;
		font-weight: 600;
	}

	&__big {
		margin-top: 2px;
		font-family: var(--ops-font-display);
		font-size: 22px;
		font-weight: 600;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}

	&__na {
		margin-top: 8px;
		font-family: var(--ops-font-display);
		font-size: 16px;
		color: var(--ops-fg-faint);
	}

	&__sub {
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--ops-fg-faint);
	}

	&__note {
		margin: 8px 0 0;
		font-size: 12px;
		color: var(--ops-fg-muted);
	}

	&__pill {
		padding: 2px 8px;
		border-radius: 999px;
		background: var(--ops-warn-wash);
		color: var(--ops-warn-text);
		font-size: 10px;
		font-weight: 600;
		white-space: nowrap;
	}
}

@media (max-width: 1024px) {
	.ops-activity { grid-template-columns: 1fr; }
}
</style>
