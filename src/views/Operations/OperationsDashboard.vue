<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="operations-dashboard" :class="{ 'operations-dashboard--mobile': isMobile }">
		<header class="operations-dashboard__bar">
			<div class="operations-dashboard__brand">
				<span class="operations-dashboard__eyebrow">{{ t('libresign', 'GoPaperless · Internal') }}</span>
				<h1>{{ t('libresign', 'Operations') }}</h1>
				<p class="operations-dashboard__sub">
					{{ t('libresign', 'Monitor signing activity, payment performance and credit usage') }}
				</p>
			</div>
			<OperationsDateRange :preset="preset"
				:custom-from="customFrom"
				:custom-to="customTo"
				:error="customError"
				@preset="setPreset"
				@custom="({ from, to }) => applyCustom(from, to)" />
		</header>

		<div class="operations-dashboard__legend" aria-hidden="true">
			<span><OperationsProvenance /> {{ t('libresign', 'Calculable from existing data') }}</span>
			<span><OperationsProvenance :confirmed="false" /> {{ t('libresign', 'Estimate, or needs instrumentation') }}</span>
			<span class="operations-dashboard__range">{{ params.from }} → {{ params.to }} · {{ params.tz }}</span>
		</div>

		<section class="operations-dashboard__section">
			<h2>{{ t('libresign', 'Overview') }}</h2>
			<OperationsPanelState :status="panels.overview.status" :error="panels.overview.error" :skeleton-rows="4" @retry="reload('overview')">
				<OperationsOverview v-if="panels.overview.data" :data="panels.overview.data" />
			</OperationsPanelState>
		</section>

		<section class="operations-dashboard__section">
			<h2>{{ t('libresign', 'Activity over time') }}</h2>
			<OperationsPanelState :status="panels.timeseries.status" :error="panels.timeseries.error" @retry="reload('timeseries')">
				<OperationsActivity v-if="panels.timeseries.data" :data="panels.timeseries.data" />
			</OperationsPanelState>
		</section>

		<div class="operations-dashboard__split">
			<section class="operations-dashboard__section">
				<h2>{{ t('libresign', 'Signing funnel') }}</h2>
				<div class="operations-dashboard__panel">
					<OperationsPanelState :status="panels.funnel.status" :error="panels.funnel.error" @retry="reload('funnel')">
						<OperationsFunnel v-if="panels.funnel.data" :data="panels.funnel.data" />
					</OperationsPanelState>
				</div>
			</section>

			<section class="operations-dashboard__section">
				<h2>{{ t('libresign', 'Credit Balances') }}</h2>
				<div class="operations-dashboard__panel">
					<OperationsPanelState :status="panels.entitlements.status" :error="panels.entitlements.error" @retry="reload('entitlements')">
						<OperationsEntitlements v-if="panels.entitlements.data" :data="panels.entitlements.data" />
					</OperationsPanelState>
				</div>
			</section>
		</div>

		<!-- Funding mix and Credits consumed come from the same entitlements request as the Entitlements panel, so a retry on any of them refetches that one request. -->
		<section v-if="showFunding" class="operations-dashboard__section">
			<h2>{{ t('libresign', 'Signing Funding') }}</h2>
			<div class="operations-dashboard__split">
				<div class="operations-dashboard__panel">
					<OperationsPanelState :status="panels.entitlements.status" :error="panels.entitlements.error" @retry="reload('entitlements')">
						<OperationsFundingMix v-if="funding" :data="funding" />
					</OperationsPanelState>
				</div>
				<div class="operations-dashboard__panel">
					<OperationsPanelState :status="panels.entitlements.status" :error="panels.entitlements.error" @retry="reload('entitlements')">
						<OperationsCreditsConsumed v-if="panels.entitlements.data" :metric="panels.entitlements.data.metrics.creditConsumption" />
					</OperationsPanelState>
				</div>
			</div>
		</section>

		<section class="operations-dashboard__section">
			<h2>{{ t('libresign', 'Payments') }}</h2>
			<OperationsPanelState :status="panels.payments.status" :error="panels.payments.error" @retry="reload('payments')">
				<OperationsPayments v-if="panels.payments.data" :data="panels.payments.data" />
			</OperationsPanelState>
		</section>

		<footer class="operations-dashboard__foot">
			{{ t('libresign', 'Figures are based on recorded GoPaperless activity. Dates follow the Nairobi time zone (UTC+3), including both the start and end dates. Payment success rates measure different things and are reported separately.') }}
		</footer>
	</div>
</template>

<script setup lang="ts">
/**
 * OPERATIONS DASHBOARD (shell)
 *
 * Composes the panels and the date range; data, per-panel state and request
 * ordering live in useOperationsData. Visibility here is a convenience only:
 * every endpoint authorizes the caller server-side.
 */
import { computed, onMounted } from 'vue'

import { t } from '@nextcloud/l10n'


import OperationsActivity from './components/OperationsActivity.vue'
import OperationsDateRange from './components/OperationsDateRange.vue'
import OperationsCreditsConsumed from './components/OperationsCreditsConsumed.vue'
import OperationsEntitlements from './components/OperationsEntitlements.vue'
import OperationsFundingMix from './components/OperationsFundingMix.vue'
import OperationsFunnel from './components/OperationsFunnel.vue'
import OperationsOverview from './components/OperationsOverview.vue'
import OperationsPanelState from './components/OperationsPanelState.vue'
import OperationsPayments from './components/OperationsPayments.vue'
import OperationsProvenance from './components/OperationsProvenance.vue'
import { useOperationsData } from './composables/useOperationsData.ts'
import type { FundingMetric } from './types.ts'
import { useBreakpoint } from '@/composables/useBreakpoint'

defineOptions({ name: 'OperationsDashboard' })

const { isMobile } = useBreakpoint()
const { preset, customFrom, customTo, customError, params, panels, reload, setPreset, applyCustom } = useOperationsData()

/** The funding block of the entitlements payload; absent or unavailable on older/limited servers. */
const funding = computed(() => {
	const f = panels.entitlements.data?.metrics.funding
	return f && (f as { available?: boolean }).available !== false ? f as FundingMetric : null
})
// Hide the whole section only once we know there is nothing to show; while loading or on error the panel states render.
const showFunding = computed(() => panels.entitlements.status !== 'ready' || funding.value !== null)

onMounted(() => reload())
</script>

<style scoped lang="scss">
@use './tokens' as ops;

.operations-dashboard {
	@include ops.ops-tokens;
	max-width: 1120px;
	margin: 0 auto;
	padding: 8px 24px 40px;
	color: var(--ops-fg);
	box-sizing: border-box;

	&--mobile {
		padding: 8px 12px 32px;
	}

	&__bar {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		justify-content: space-between;
		gap: 12px 16px;
		padding: 14px 0 16px;
		border-bottom: 1px solid var(--ops-border);
	}

	&__brand {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-width: 0;

		h1 {
			margin: 0;
			font-family: var(--ops-font-display);
			font-size: 24px;
			font-weight: 700;
			letter-spacing: -0.01em;
		}
	}

	&__eyebrow {
		font-family: var(--ops-font-mono);
		font-size: 11px;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ops-fg-faint);
	}

	&__sub {
		margin: 0;
		font-size: 13.5px;
		color: var(--ops-fg-muted);
		max-width: 62ch;
	}

	&__legend {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 18px;
		margin: 16px 0 4px;
		font-size: 11.5px;
		color: var(--ops-fg-muted);

		span { display: inline-flex; align-items: center; gap: 7px; }
	}

	&__range {
		font-family: var(--ops-font-mono);
		color: var(--ops-fg-faint);
	}

	&__section {
		margin-top: 26px;

		h2 {
			margin: 0 0 12px;
			font-family: var(--ops-font-display);
			font-size: 14px;
			font-weight: 600;
			letter-spacing: -0.01em;
		}
	}

	&__split {
		display: grid;
		grid-template-columns: 2fr 1fr;
		gap: 12px;
		align-items: stretch;

		.operations-dashboard__section {
			display: flex;
			flex-direction: column;
			margin-top: 26px;
		}

		.operations-dashboard__panel { flex: 1; }
	}

	&__panel {
		padding: 18px;
		background: var(--ops-surface);
		border: 1px solid var(--ops-border);
		border-radius: var(--ops-radius);
	}

	&__foot {
		margin-top: 30px;
		padding-top: 16px;
		border-top: 1px solid var(--ops-border);
		font-size: 11px;
		line-height: 1.6;
		color: var(--ops-fg-faint);
	}
}

@media (max-width: 1024px) {
	.operations-dashboard__split { grid-template-columns: 1fr; }
}
</style>
