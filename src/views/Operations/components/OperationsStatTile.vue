<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ops-tile">
		<OperationsProvenance class="ops-tile__prov" :confirmed="confirmed" />
		<div class="ops-tile__icon" :class="`ops-tile__icon--${tone}`">
			<OperationsIcon :path="icon" size="lg" />
		</div>
		<div>
			<div v-if="unavailable" class="ops-tile__na">
				{{ t('libresign', 'Not available') }}
			</div>
			<div v-else class="ops-tile__value">
				<slot name="value">
					{{ value }}
				</slot>
			</div>
			<div class="ops-tile__label">
				{{ label }}
			</div>
		</div>
		<div v-if="$slots.sub" class="ops-tile__sub">
			<slot name="sub" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'


import OperationsIcon from './OperationsIcon.vue'
import OperationsProvenance from './OperationsProvenance.vue'

defineOptions({ name: 'OperationsStatTile' })

withDefaults(defineProps<{
	icon: string
	label: string
	value?: string
	tone?: 'green' | 'blue' | 'amber'
	unavailable?: boolean
	confirmed?: boolean
}>(), {
	value: '',
	tone: 'green',
	unavailable: false,
	confirmed: true,
})
</script>

<style scoped lang="scss">
.ops-tile {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 116px;
	padding: 16px;
	background: var(--ops-surface);
	border: 1px solid var(--ops-border);
	border-radius: var(--ops-radius);

	&__prov {
		position: absolute;
		top: 12px;
		right: 12px;
	}

	&__icon {
		display: grid;
		place-items: center;
		width: 34px;
		height: 34px;
		border-radius: 9px;
		background: var(--ops-green-wash);
		color: var(--ops-green-text);

		&--blue { background: rgba(37, 99, 235, 0.12); color: var(--ops-info); }
		&--amber { background: var(--ops-warn-wash); color: var(--ops-warn); }
	}

	&__value,
	&__na {
		font-family: var(--ops-font-display);
		font-size: 28px;
		font-weight: 600;
		line-height: 1;
		letter-spacing: -0.025em;
		font-variant-numeric: tabular-nums;
	}

	&__na {
		font-size: 16px;
		color: var(--ops-fg-faint);
	}

	&__label {
		margin-top: 4px;
		font-size: 12.5px;
		color: var(--ops-fg-muted);
	}

	&__sub {
		font-size: 11.5px;
		color: var(--ops-fg-muted);
		font-variant-numeric: tabular-nums;
	}
}
</style>
