<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-funnel">
		<div class="ops-funnel__head">
			<OperationsProvenance />
			<span class="ops-funnel__title">{{ t('libresign', 'Signing funnel') }}</span>
		</div>

		<div class="ops-funnel__stages">
			<div v-for="stage in stages" :key="stage.key" class="ops-funnel__stage">
				<div class="ops-funnel__name">
					<span>{{ stage.label }}</span>
					<span class="ops-funnel__unit">{{ stage.unitLabel }}</span>
				</div>
				<div class="ops-funnel__bar">
					<div class="ops-funnel__fill" :style="{ width: `${stage.width}%`, background: stage.color }">
						{{ formatInt(stage.count) }}
					</div>
				</div>
			</div>
		</div>

		<p class="ops-funnel__note">
			<OperationsIcon :path="mdiAlertOutline" size="xs" />
			<span>
				{{ t('libresign', 'These stages count different things: documents ready for signing, individual signatures, and fully signed documents. Each stage uses its own date, so the figures are not directly comparable and should not be interpreted as a conversion rate.') }}
			</span>
		</p>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'
import { mdiAlertOutline } from '@mdi/js'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'
import type { FunnelResponse } from '../types.ts'
import { formatInt } from '../utils/format.ts'

defineOptions({ name: 'OperationsFunnel' })

const props = defineProps<{ data: FunnelResponse }>()

const LABELS: Record<string, () => string> = {
	workflowsInitiated: () => t('libresign', 'Workflows initiated'),
	signaturesCompleted: () => t('libresign', 'Signatures completed'),
	fullyCompletedDocuments: () => t('libresign', 'Fully signed documents'),
}
const UNITS: Record<string, () => string> = {
	documents: () => t('libresign', 'documents'),
	signatures: () => t('libresign', 'signatures'),
}
const COLORS = ['var(--ops-cat-a)', 'var(--ops-green)', 'var(--ops-green-strong)']

const stages = computed(() => {
	// Bar width is a visual proportion of the largest stage only — never a
	// conversion rate between stages, whose units differ.
	const max = Math.max(1, ...props.data.stages.map(s => s.count))
	return props.data.stages.map((s, i) => ({
		...s,
		label: LABELS[s.key]?.() ?? s.key,
		unitLabel: UNITS[s.unit]?.() ?? s.unit,
		color: COLORS[i] ?? COLORS[0],
		width: Math.round((s.count / max) * 100),
	}))
})
</script>

<style scoped lang="scss">
.ops-funnel {
	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
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

	&__stages {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__stage {
		display: grid;
		grid-template-columns: 180px 1fr;
		align-items: center;
		gap: 12px;
	}

	&__name {
		display: flex;
		flex-direction: column;
		font-size: 12.5px;
		color: var(--ops-fg-muted);
	}

	&__unit {
		font-size: 10.5px;
		color: var(--ops-fg-faint);
		font-family: var(--ops-font-mono);
	}

	&__bar {
		height: 34px;
		border-radius: 7px;
		background: var(--ops-sunken);
		overflow: hidden;
	}

	&__fill {
		display: flex;
		align-items: center;
		min-width: 2.5em;
		height: 100%;
		padding-left: 12px;
		border-radius: 7px;
		color: #fff;
		font-size: 13px;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__pct {
		min-width: 52px;
		text-align: right;
		font-family: var(--ops-font-mono);
		font-size: 12px;
		color: var(--ops-fg-muted);

		b { color: var(--ops-fg); font-weight: 600; }
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

@media (max-width: 768px) {
	.ops-funnel__stage {
		grid-template-columns: 1fr;
		grid-template-areas: "name" "bar";
		gap: 6px;
	}

	.ops-funnel__name { grid-area: name; }
	.ops-funnel__bar { grid-area: bar; }
}

@media (prefers-reduced-motion: reduce) {
	.ops-funnel__fill { transition: none; }
}
</style>
