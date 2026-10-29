<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-overview">
		<OperationsStatTile :icon="mdiAccountGroupOutline"
			:label="t('libresign', 'Registered accounts (lifetime)')"
			:value="registered"
			:unavailable="isUnavailable(m.registeredAccountsLifetime)">
			<template #sub>
				<template v-if="!isUnavailable(m.enabledAccounts)">
					{{ t('libresign', '{count} currently enabled', { count: formatInt(m.enabledAccounts.value) }) }}
				</template>
			</template>
		</OperationsStatTile>

		<!-- Account holders only. Email-identified signers are left out: the inclusive figure (distinctSignersInclEmail) is an upper bound that can count one person twice, so it is not shown. -->
		<OperationsStatTile :icon="mdiDrawPen"
			:label="t('libresign', 'Users who signed')"
			:value="formatInt(m.usersWhoSigned.value)">
			<template #sub>
				{{ t('libresign', 'Account holders this period') }}
			</template>
		</OperationsStatTile>

		<OperationsStatTile :icon="mdiCheckCircleOutline"
			:label="t('libresign', 'Completed documents')"
			:value="formatInt(m.completedDocuments.value)">
			<template #sub>
				{{ t('libresign', '{count} documents created', { count: formatInt(m.documentsCreated.value) }) }}
			</template>
		</OperationsStatTile>

		<OperationsStatTile :icon="mdiSpeedometer"
			:label="t('libresign', 'Signing payment success rate')"
			:value="rate.rate === null ? '—' : `${formatPercent(rate.rate)}%`">
			<template #sub>
				{{ t('libresign', '{paid} of {total} attempts', { paid: formatInt(rate.numerator), total: formatInt(rate.denominator) }) }}
			</template>
		</OperationsStatTile>

		<!-- One line per currency; currencies are never summed because there is no FX conversion. -->
		<OperationsStatTile :icon="mdiCashMultiple" :label="t('libresign', 'Revenue collected')">
			<template #value>
				<template v-if="m.revenue.byCurrency.length === 0">
					—
				</template>
				<div v-for="row in m.revenue.byCurrency" :key="row.currency" class="ops-overview__money">
					{{ formatMoney(row.amountMinor, row.currency) }}
				</div>
			</template>
			<template #sub>
				{{ t('libresign', 'Confirmed payments, by currency') }}
			</template>
		</OperationsStatTile>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import {
	mdiAccountGroupOutline,
	mdiCashMultiple,
	mdiCheckCircleOutline,
	mdiDrawPen,
	mdiSpeedometer,
} from '@mdi/js'

import OperationsStatTile from './OperationsStatTile.vue'
import { isUnavailable, type OverviewResponse } from '../types.ts'
import { formatInt, formatMoney, formatPercent } from '../utils/format.ts'

defineOptions({ name: 'OperationsOverview' })

const props = defineProps<{ data: OverviewResponse }>()

const m = computed(() => props.data.metrics)
// Per-attempt signing rate only. The workflow-level and credit-purchase rates live in
// the Payments panel because their denominators differ.
const rate = computed(() => m.value.signingPaymentSuccess)
// '' when unavailable: the tile then shows its "Not available" state, so a failed count never reads as 0.
const registered = computed(() => {
	const metric = m.value.registeredAccountsLifetime
	return isUnavailable(metric) ? '' : formatInt(metric.value)
})
</script>

<style scoped lang="scss">
.ops-overview {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 12px;

	&__money {
		font-size: 20px;
		line-height: 1.15;
	}
}

@media (max-width: 1024px) {
	.ops-overview {
		grid-template-columns: repeat(2, 1fr);

		// An odd tile count would leave an empty half-row; let the last tile take it.
		> :last-child:nth-child(odd) { grid-column: 1 / -1; }
	}
}

@media (max-width: 360px) {
	.ops-overview { grid-template-columns: 1fr; }
}
</style>
