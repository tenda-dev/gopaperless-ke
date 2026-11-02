<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-payments">
		<!-- The three success figures use different units and are never blended. -->
		<div class="ops-payments__rates">
			<div v-for="r in rates" :key="r.key" class="ops-payments__rate">
				<div class="ops-payments__rate-label">
					<OperationsProvenance />
					{{ r.label }}
				</div>
				<div class="ops-payments__rate-value">
					{{ r.metric.rate === null ? '—' : `${formatPercent(r.metric.rate)}%` }}
				</div>
				<div class="ops-payments__rate-sub">
					{{ t('libresign', '{n} of {d} {unit}', { n: formatInt(r.metric.numerator), d: formatInt(r.metric.denominator), unit: r.unit }) }}
				</div>
			</div>
		</div>

		<div class="ops-payments__grid">
			<section class="ops-payments__panel">
				<div class="ops-payments__head">
					<span class="ops-payments__title">{{ t('libresign', 'Signing payment attempts') }}</span>
					<span class="ops-payments__sub">{{ t('libresign', '{count} attempts', { count: formatInt(signTotal) }) }}</span>
				</div>
				<div v-if="signTotal > 0"
					class="ops-payments__bar"
					role="img"
					:aria-label="t('libresign', 'Signing payment outcomes')">
					<i v-for="o in signOutcomes"
						:key="o.key"
						:style="{ width: `${(o.count / signTotal) * 100}%`, background: o.color }" />
				</div>
				<ul class="ops-payments__rows">
					<li v-for="o in signOutcomes" :key="o.key">
						<span class="ops-payments__swatch" :style="{ background: o.color }" />
						<span class="ops-payments__row-name">{{ o.label }}</span>
						<span class="ops-payments__row-val">{{ formatInt(o.count) }}</span>
						<span class="ops-payments__row-pct">{{ signTotal ? ((o.count / signTotal) * 100).toFixed(1) : '0.0' }}%</span>
					</li>
				</ul>
			</section>

			<section class="ops-payments__panel">
				<div class="ops-payments__head">
					<span class="ops-payments__title">{{ t('libresign', 'Payment outcomes by provider') }}</span>
					<span class="ops-payments__sub">{{ t('libresign', 'Paid vs. other outcomes') }}</span>
				</div>
				<div class="ops-payments__providers">
					<div v-for="p in providers" :key="p.provider" class="ops-payments__provider">
						<div class="ops-payments__provider-bars">
							<div class="ops-payments__col"
								:style="{ height: `${p.paidPct}%`, background: 'var(--ops-cat-a)' }"
								:title="`${p.name} · ${t('libresign', 'paid')} ${formatInt(p.paid)}`" />
							<div class="ops-payments__col"
								:style="{ height: `${p.otherPct}%`, background: 'var(--ops-cat-b)' }"
								:title="`${p.name} · ${t('libresign', 'other')} ${formatInt(p.other)}`" />
						</div>
						<div class="ops-payments__provider-name">
							{{ p.name }}
						</div>
						<div class="ops-payments__provider-nums">
							{{ formatInt(p.paid) }} / {{ formatInt(p.paid + p.other) }}
						</div>
					</div>
				</div>
				<div class="ops-payments__legend">
					<span><i style="background: var(--ops-cat-a)" />{{ t('libresign', 'Paid') }}</span>
					<span><i style="background: var(--ops-cat-b)" />{{ t('libresign', 'Pending, failed, expired or cancelled') }}</span>
				</div>
				<p class="ops-payments__note">
					<OperationsIcon :path="mdiAlertOutline" size="xs" />
					<span>{{ t('libresign', 'Daraja and DPO support payment verification through their respective provider mechanisms. Pending payments may still be awaiting confirmation, so pending should not automatically be interpreted as failed.') }}</span>
				</p>
			</section>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiAlertOutline } from '@mdi/js'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { PaymentsResponse } from '../types.ts'
import { formatInt, formatPercent } from '../utils/format.ts'

defineOptions({ name: 'OperationsPayments' })

const props = defineProps<{ data: PaymentsResponse }>()

const PROVIDER_NAMES: Record<string, string> = { daraja: 'Daraja', dpo: 'DPO' }

const rates = computed(() => {
	const m = props.data.metrics
	return [
		{ key: 'sign', label: t('libresign', 'Signing payment success rate'), unit: t('libresign', 'attempts'), metric: m.signingPaymentSuccess },
		{ key: 'workflow', label: t('libresign', 'Signing workflow success rate'), unit: t('libresign', 'workflows'), metric: m.signingWorkflowSuccess },
		{ key: 'credit', label: t('libresign', 'Credit-purchase success rate'), unit: t('libresign', 'attempts'), metric: m.creditPurchaseSuccess },
	]
})

// Breakdown of ALL signing attempts. Pending and "could not start" are shown here but
// sit outside the success-rate denominator above (paid + failed + expired + cancelled).
const signOutcomes = computed(() => {
	const s = props.data.outcomes.signRequest
	const n = (...keys: string[]) => keys.reduce((sum, k) => sum + (s[k] ?? 0), 0)
	return [
		{ key: 'paid', label: t('libresign', 'Paid'), count: n('paid'), color: 'var(--ops-ok)' },
		{ key: 'pending', label: t('libresign', 'Pending'), count: n('pending'), color: 'var(--ops-warn)' },
		{ key: 'failed', label: t('libresign', 'Failed, expired or cancelled'), count: n('failed', 'expired', 'cancelled'), color: 'var(--ops-crit)' },
		{ key: 'initiation_failed', label: t('libresign', 'Could not start'), count: n('initiation_failed'), color: 'var(--ops-fg-faint)' },
	]
})
const signTotal = computed(() => signOutcomes.value.reduce((sum, o) => sum + o.count, 0))

const providers = computed(() => {
	const rows = props.data.providers.map((p) => {
		const paid = p.byStatus.paid ?? 0
		// `other` includes pending attempts, which provider verification may still confirm: it is not a failure count.
		return { provider: p.provider, name: PROVIDER_NAMES[p.provider] ?? p.provider, paid, other: p.attempts - paid }
	})
	const max = Math.max(1, ...rows.map((r) => Math.max(r.paid, r.other))) * 1.1
	return rows.map((r) => ({ ...r, paidPct: (r.paid / max) * 100, otherPct: (r.other / max) * 100 }))
})
</script>

<style scoped lang="scss">
.ops-payments {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__rates {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 12px;
	}

	&__rate {
		padding: 12px 14px;
		background: var(--ops-sunken);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius-sm);
	}

	&__rate-label {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 12px;
		color: var(--ops-fg-muted);
	}

	&__rate-value {
		margin-top: 4px;
		font-family: var(--ops-font-display);
		font-size: 24px;
		font-weight: 600;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}

	&__rate-sub {
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--ops-fg-faint);
	}

	&__grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
	}

	&__panel {
		padding: 16px;
		background: var(--ops-surface);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius);
	}

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__title {
		font-size: 12.5px;
		font-weight: 600;
	}

	&__sub {
		margin-left: auto;
		font-family: var(--ops-font-mono);
		font-size: 11px;
		color: var(--ops-fg-faint);
	}

	&__bar {
		display: flex;
		gap: 2px;
		height: 16px;
		overflow: hidden;
		border-radius: 5px;
		background: var(--ops-sunken);

		i { display: block; height: 100%; }
	}

	&__rows {
		display: flex;
		flex-direction: column;
		gap: 9px;
		margin: 14px 0 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			align-items: center;
			gap: 10px;
			font-size: 12.5px;
		}
	}

	&__swatch {
		flex: none;
		width: 9px;
		height: 9px;
		border-radius: 3px;
	}

	&__row-name { flex: 1; color: var(--ops-fg-muted); }
	&__row-val { font-family: var(--ops-font-mono); font-weight: 600; }
	&__row-pct { min-width: 44px; text-align: right; font-family: var(--ops-font-mono); color: var(--ops-fg-faint); }

	&__providers {
		display: flex;
		gap: 14px;
		height: 150px;
	}

	&__provider {
		display: flex;
		flex: 1;
		flex-direction: column;
		align-items: center;
		justify-content: flex-end;
		gap: 4px;
		height: 100%;
	}

	&__provider-bars {
		display: flex;
		align-items: flex-end;
		justify-content: center;
		gap: 5px;
		width: 100%;
		flex: 1;
	}

	&__col {
		width: 26px;
		min-height: 2px;
		border-radius: 5px 5px 0 0;
		transition: height 0.4s ease;
	}

	&__provider-name { font-size: 11px; font-weight: 600; color: var(--ops-fg-muted); }
	&__provider-nums { font-family: var(--ops-font-mono); font-size: 10.5px; color: var(--ops-fg-faint); }

	&__legend {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 16px;
		margin-top: 8px;
		font-size: 11.5px;
		color: var(--ops-fg-muted);

		span { display: inline-flex; align-items: center; gap: 6px; }
		i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; }
	}

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

@media (max-width: 1024px) {
	.ops-payments__grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
	.ops-payments__rates { grid-template-columns: 1fr; }
}

@media (prefers-reduced-motion: reduce) {
	.ops-payments__col { transition: none; }
}
</style>
