<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-fund">
		<div class="ops-fund__head">
			<OperationsProvenance />
			<span class="ops-fund__title">{{ t('libresign', 'Signing funding') }}</span>
			<span class="ops-fund__sub">{{ t('libresign', 'How completed documents were funded during this period.') }}</span>
		</div>

		<p v-if="isEmpty" class="ops-fund__none">
			{{ t('libresign', 'No documents were completed in this period.') }}
		</p>

		<template v-else>
			<div class="ops-fund__rows">
				<div v-for="row in rows" :key="row.key" class="ops-fund__row">
					<div class="ops-fund__text">
						<div class="ops-fund__name">
							{{ row.label }}
						</div>
						<div class="ops-fund__hint">
							{{ row.hint }}
						</div>
					</div>
					<div class="ops-fund__bar" aria-hidden="true">
						<div class="ops-fund__fill" :class="{ 'ops-fund__fill--muted': row.muted }" :style="{ width: `${row.width}%` }" />
					</div>
					<div class="ops-fund__value">
						{{ formatInt(row.count) }}
					</div>
				</div>
			</div>

			<div v-if="pools.length" class="ops-fund__pools">
				<div class="ops-fund__group-title">
					{{ t('libresign', 'Completed documents by credit type') }}
				</div>
				<ul>
					<li v-for="p in pools" :key="p.key">
						<span>{{ p.label }}</span><b>{{ formatInt(p.count) }}</b>
					</li>
				</ul>
			</div>

			<p class="ops-fund__note">
				<OperationsIcon :path="mdiAlertOutline" size="xs" />
				<span>
					{{ t('libresign', 'A document may appear in multiple categories if its signers used different funding methods. Category counts can therefore add up to more than the {count} completed documents in this period.', { count: formatInt(data.completedDocuments) }) }}
				</span>
			</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiAlertOutline } from '@mdi/js'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { FundingMetric } from '../types.ts'
import { formatInt } from '../utils/format.ts'
import { poolRows } from '../utils/funding.ts'

defineOptions({ name: 'OperationsFundingMix' })

const props = defineProps<{ data: FundingMetric }>()

// No completed documents: say so instead of drawing empty bars that would read as 0% funding.
const isEmpty = computed(() => props.data.completedDocuments === 0)

const rows = computed(() => {
	const c = props.data.categories
	const total = Math.max(1, props.data.completedDocuments)
	// A document appears in every category any of its signers used, so categories overlap.
	// directPay holds paid-but-unsettled signings only (a paid signer payment becomes a
	// purchased entitlement that settlement then consumes). "Undetermined" means neither a
	// consumption marker nor a paid payment was found (free, unsettled or legacy), not
	// "unfunded", which is why it is muted instead of presented as a funding method.
	const defs = [
		{ key: 'requesterSponsored', label: t('libresign', 'Requester-sponsored'), hint: t('libresign', 'Credit from the requester’s balance'), count: c.requesterSponsored, muted: false },
		{ key: 'selfEntitlement', label: t('libresign', 'Signer’s own credits'), hint: t('libresign', 'Credit from the signer’s balance'), count: c.selfEntitlement, muted: false },
		{ key: 'directPay', label: t('libresign', 'Paid per signing'), hint: t('libresign', 'Payment recorded without credit use'), count: c.directPay, muted: false },
		{ key: 'unknown', label: t('libresign', 'Funding undetermined'), hint: t('libresign', 'Free signing, unsettled funding, or older records'), count: c.unknown, muted: true },
	]
	// Bar width is the share of completed documents that touch this category (categories overlap, so bars are independent).
	return defs.map(d => ({ ...d, width: Math.min(100, Math.round((d.count / total) * 100)) }))
})

const pools = computed(() => poolRows(props.data.byEntitlementType))
</script>

<style scoped lang="scss">
.ops-fund {
	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__title { font-size: 12.5px; font-weight: 600; }

	&__sub {
		margin-inline-start: auto;
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--ops-fg-faint);
	}

	&__none { margin: 6px 0 0; font-size: 12px; color: var(--ops-fg-faint); }

	&__rows {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__row {
		display: grid;
		grid-template-columns: minmax(150px, 1fr) minmax(80px, 1.2fr) auto;
		align-items: center;
		gap: 12px;
		padding: 11px 13px;
		background: var(--ops-sunken);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius-sm);
	}

	&__text { min-width: 0; }
	&__name { font-size: 12.5px; font-weight: 600; }
	&__hint { font-size: 11px; color: var(--ops-fg-faint); }

	&__bar {
		height: 8px;
		border-radius: 999px;
		background: var(--ops-surface);
		border: 1px solid var(--ops-border);
		overflow: hidden;
	}

	&__fill {
		height: 100%;
		border-radius: 999px;
		background: var(--ops-cat-a);
		transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);

		&--muted { background: var(--ops-border-strong); }
	}

	&__value {
		min-width: 2.5em;
		text-align: end;
		font-family: var(--ops-font-display);
		font-size: 19px;
		font-weight: 600;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}

	&__pools {
		margin-top: 14px;

		ul { margin: 6px 0 0; padding: 0; list-style: none; }

		li {
			display: flex;
			justify-content: space-between;
			gap: 8px;
			padding: 4px 0;
			font-size: 12.5px;
			color: var(--ops-fg-muted);

			b { color: var(--ops-fg); font-family: var(--ops-font-mono); }
		}
	}

	&__group-title { font-size: 11.5px; font-weight: 600; }

	&__note {
		display: flex;
		gap: 9px;
		margin: 12px 0 0;
		padding: 10px 12px;
		background: var(--ops-sunken);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius-sm);
		font-size: 11.5px;
		line-height: 1.5;
		color: var(--ops-fg-muted);

		:deep(.ops-icon) { color: var(--ops-warn); flex: none; margin-top: 2px; }
	}
}

@media (max-width: 600px) {
	.ops-fund__row {
		grid-template-columns: 1fr auto;
		grid-template-areas: "text value" "bar bar";
		gap: 8px 12px;
	}

	.ops-fund__text { grid-area: text; }
	.ops-fund__value { grid-area: value; }
	.ops-fund__bar { grid-area: bar; }
}

@media (prefers-reduced-motion: reduce) {
	.ops-fund__fill { transition: none; }
}
</style>
