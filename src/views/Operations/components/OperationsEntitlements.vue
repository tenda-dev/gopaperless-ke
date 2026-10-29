<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-ent">
		<div class="ops-ent__head">
			<OperationsProvenance />
			<span class="ops-ent__title">{{ t('libresign', 'Credits') }}</span>
			<span class="ops-ent__sub">{{ t('libresign', 'Current balances, excluding expired credits') }}</span>
		</div>

		<div class="ops-ent__rows">
			<div v-for="row in rows" :key="row.key" class="ops-ent__row">
				<div class="ops-ent__icon" :class="`ops-ent__icon--${row.tone}`">
					<OperationsIcon :path="row.icon" size="sm" />
				</div>
				<div class="ops-ent__text">
					<div class="ops-ent__name">
						{{ row.label }}
					</div>
					<div class="ops-ent__hint">
						{{ row.hint }}
					</div>
				</div>
				<div class="ops-ent__value">
					{{ formatInt(row.value) }}
				</div>
			</div>

			<div v-if="consumption" class="ops-ent__row">
				<div class="ops-ent__icon ops-ent__icon--amber">
					<OperationsIcon :path="mdiLightningBolt" size="sm" />
				</div>
				<div class="ops-ent__text">
					<div class="ops-ent__name">
						{{ t('libresign', 'Credits consumed') }}
					</div>
					<div class="ops-ent__hint">
						{{ t('libresign', 'by signing date, this period') }}
					</div>
				</div>
				<div class="ops-ent__value">
					{{ formatInt(consumption.total) }}
				</div>
			</div>
			<div v-else class="ops-ent__row ops-ent__row--na">
				<div class="ops-ent__icon ops-ent__icon--amber">
					<OperationsIcon :path="mdiLightningBolt" size="sm" />
				</div>
				<div class="ops-ent__text">
					<div class="ops-ent__name">
						{{ t('libresign', 'Credits consumed') }}
					</div>
					<div class="ops-ent__hint">
						{{ t('libresign', 'Not tracked in this release') }}
					</div>
				</div>
				<span class="ops-ent__pill">{{ t('libresign', 'unavailable') }}</span>
			</div>
		</div>

		<p v-if="balances.unlimitedEntitlements > 0" class="ops-ent__note">
			<OperationsIcon :path="mdiInfinity" size="xs" />
			<span>{{ t('libresign', '{count} unlimited credit entitlements are excluded from the totals above.', { count: formatInt(balances.unlimitedEntitlements) }) }}</span>
		</p>

		<div class="ops-ent__groups">
			<div class="ops-ent__group">
				<div class="ops-ent__group-title">
					{{ t('libresign', 'Credits granted this period') }}
				</div>
				<ul v-if="grants.length">
					<li v-for="g in grants" :key="g.key">
						<span>{{ g.label }}</span><b>{{ formatInt(g.count) }}</b>
					</li>
				</ul>
				<p v-else class="ops-ent__none">
					{{ t('libresign', 'No credits granted in this period.') }}
				</p>
			</div>
			<div class="ops-ent__group">
				<div class="ops-ent__group-title">
					{{ t('libresign', 'Sponsorship activity') }}
				</div>
				<ul v-if="sponsorship.length">
					<li v-for="s in sponsorship" :key="s.key">
						<span>{{ s.label }}</span><b>{{ formatInt(s.count) }}</b>
					</li>
				</ul>
				<p v-else class="ops-ent__none">
					{{ t('libresign', 'No sponsorship activity in this period.') }}
				</p>
				<p v-if="requesterActivity" class="ops-ent__foot">
					{{ t('libresign', '{records} requester sponsorship records across {documents} documents. Reserved credits are committed to signings in progress and are only consumed when signing succeeds.', {
						records: formatInt(requesterActivity.records),
						documents: formatInt(requesterActivity.documents),
					}) }}
				</p>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiCashMultiple, mdiInfinity, mdiLightningBolt, mdiLockOutline } from '@mdi/js'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { CreditConsumptionMetric, EntitlementsResponse, Unavailable } from '../types.ts'
import { formatInt } from '../utils/format.ts'

defineOptions({ name: 'OperationsEntitlements' })

const props = defineProps<{ data: EntitlementsResponse }>()

const balances = computed(() => props.data.metrics.availableCredits)

// available = remaining - reserved. Reserved credits are committed to signings in
// progress and are consumed only when signing succeeds, so they are neither available nor spent.
const rows = computed(() => [
	{ key: 'available', icon: mdiCashMultiple, tone: 'green', label: t('libresign', 'Available credits'), hint: t('libresign', 'Available to use for signing'), value: balances.value.available },
	{ key: 'reserved', icon: mdiLockOutline, tone: 'green', label: t('libresign', 'Reserved credits'), hint: t('libresign', 'Set aside for signing in progress'), value: balances.value.reserved },
	{ key: 'remaining', icon: mdiCashMultiple, tone: 'green', label: t('libresign', 'Remaining'), hint: t('libresign', 'Total credits before reservations'), value: balances.value.remaining },
])

const GRANT_LABELS: Record<string, () => string> = {
	PAY_AS_YOU_GO: () => t('libresign', 'Purchased'),
	SUBSCRIPTION: () => t('libresign', 'Subscription'),
	PROMOTIONAL: () => t('libresign', 'Promotional'),
	ADMIN_GRANTED: () => t('libresign', 'Admin-granted'),
}
const SPONSOR_LABELS: Record<string, () => string> = {
	self: () => t('libresign', 'Signer pays'),
	requester: () => t('libresign', 'Requester pays'),
}

function toRows(byType: Record<string, number>, labels: Record<string, () => string>) {
	return Object.entries(byType)
		.map(([key, count]) => ({ key, count, label: labels[key]?.() ?? key }))
		.sort((a, b) => b.count - a.count)
}

const grants = computed(() => toRows(props.data.metrics.creditGrants.byType, GRANT_LABELS))
// Sponsorship records created in the period (intent): not proof a signing completed or a credit was consumed.
const sponsorship = computed(() => toRows(props.data.metrics.sponsorshipMix.byType, SPONSOR_LABELS))

/**
 * null while the server reports credit consumption as unavailable (keeps the "unavailable" row).
 * Otherwise dated by signing date (a proxy; no consumption timestamp exists) and counting
 * settled signings only, never reservations.
 */
const consumption = computed(() => {
	const c = props.data.metrics.creditConsumption
	return (c as Unavailable).available === false ? null : c as CreditConsumptionMetric
})

/** Only shown when there is something to say; absent on older servers. */
const requesterActivity = computed(() => {
	const a = props.data.metrics.sponsorshipMix.requesterActivity
	return a && a.records > 0 ? a : null
})
</script>

<style scoped lang="scss">
.ops-ent {
	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__title { font-size: 12.5px; font-weight: 600; }

	&__sub {
		margin-left: auto;
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--ops-fg-faint);
	}

	&__rows {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		padding: 11px 13px;
		background: var(--ops-sunken);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius-sm);
	}

	&__icon {
		display: grid;
		flex: none;
		place-items: center;
		width: 30px;
		height: 30px;
		border-radius: 8px;
		background: var(--ops-green-wash);
		color: var(--ops-green-text);

		&--blue { background: rgba(37, 99, 235, 0.12); color: var(--ops-info); }
		&--amber { background: var(--ops-warn-wash); color: var(--ops-warn); }
	}

	&__text { flex: 1; min-width: 0; }
	&__name { font-size: 12.5px; font-weight: 600; }
	&__hint { font-size: 11px; color: var(--ops-fg-faint); }

	&__value {
		font-family: var(--ops-font-display);
		font-size: 19px;
		font-weight: 600;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}

	&__pill {
		padding: 2px 8px;
		border-radius: 999px;
		border: 1px solid var(--ops-border);
		background: var(--ops-surface);
		color: var(--ops-fg-faint);
		font-size: 10px;
		font-weight: 600;
	}

	&__note {
		display: flex;
		gap: 9px;
		margin: 12px 0 0;
		font-size: 11.5px;
		color: var(--ops-fg-muted);

		:deep(.ops-icon) { flex: none; margin-top: 2px; }
	}

	&__groups {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
		margin-top: 14px;
	}

	&__group {
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
	&__none { margin: 6px 0 0; font-size: 12px; color: var(--ops-fg-faint); }
	&__foot { margin: 8px 0 0; font-size: 11.5px; line-height: 1.5; color: var(--ops-fg-faint); }
}

@media (max-width: 480px) {
	.ops-ent__groups { grid-template-columns: 1fr; }
}
</style>
