<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="age-card">
		<header class="age-card__head">
			<div class="age-card__titles">
				<h3>{{ t('libresign', 'Recent payments') }}</h3>
				<span class="age-card__sub">{{ t('libresign', 'Latest {n} retrieved · newest first', { n: payments.length }) }}</span>
			</div>
		</header>

		<div class="age-banner">
			<NcIconSvgWrapper :path="mdiInformationOutline" :size="16" />
			<span>{{ t('libresign', 'Context only. A paid record here does not confirm that credits were allocated, and this is only the latest {n} payments — not the full history.', { n: payments.length }) }}</span>
		</div>

		<div v-if="!payments.length" class="age-empty">{{ t('libresign', 'No payments on record.') }}</div>

		<template v-else>
			<div class="age-tabletools">
				<div class="age-search">
					<NcIconSvgWrapper class="age-search__icon" :path="mdiMagnify" :size="16" />
					<input
						v-model="table.term.value"
						type="search"
						class="age-search__input"
						:placeholder="t('libresign', 'Filter status, provider, reference')"
						:aria-label="t('libresign', 'Filter payments')">
					<button v-if="table.term.value" type="button" class="age-search__clear" :aria-label="t('libresign', 'Clear filter')" @click="table.clear()">
						<NcIconSvgWrapper :path="mdiClose" :size="14" />
					</button>
				</div>
			</div>

			<div v-if="!table.filteredTotal.value" class="age-empty">{{ t('libresign', 'No payments match your filter.') }}</div>

			<div v-else class="age-tablewrap">
				<table class="age-table">
					<thead>
						<tr>
							<th>{{ t('libresign', 'Date') }}</th>
							<th>{{ t('libresign', 'Status') }}</th>
							<th>{{ t('libresign', 'Purpose') }}</th>
							<th>{{ t('libresign', 'Provider') }}</th>
							<th class="age-num">{{ t('libresign', 'Amount') }}</th>
							<th>{{ t('libresign', 'Reference') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="p in table.pageRows.value" :key="p.id" class="age-row-click" tabindex="0" role="button" :aria-label="t('libresign', 'View payment details')" @click="$emit('open', p.id)" @keydown.enter.prevent="$emit('open', p.id)" @keydown.space.prevent="$emit('open', p.id)">
							<td class="age-mono age-muted">{{ formatDate(p.createdAt) }}</td>
							<td><span class="age-chip" :class="'age-chip--' + statusTone(p.status)">{{ statusLabel(p.status) }}</span></td>
							<td>{{ purposeLabel(p.purpose) }}</td>
							<td class="age-cap">{{ p.provider }}</td>
							<td class="age-num">{{ formatAmount(p) }}</td>
							<td>
								<span v-if="p.providerReference" class="age-mono age-ref" :title="p.providerReference">{{ p.providerReference }}</span>
								<span v-else class="age-muted">—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<TablePager
				:page="table.page.value"
				:page-count="table.pageCount.value"
				:page-size="table.pageSize.value"
				:filtered-total="table.filteredTotal.value"
				:range-start="table.rangeStart.value"
				:range-end="table.rangeEnd.value"
				@update:page="table.setPage"
				@update:page-size="table.setPageSize" />
		</template>

		<p class="age-readonly">
			<NcIconSvgWrapper :path="mdiLockOutline" :size="14" />
			{{ t('libresign', 'Read-only. Click a row to inspect details. Payments cannot be edited, refunded, or retried here.') }}
		</p>
	</section>
</template>

<script setup lang="ts">
import { toRef } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiMagnify, mdiClose, mdiInformationOutline, mdiLockOutline } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import TablePager from './TablePager.vue'
import { useLocalTable } from './useLocalTable'
import type { PaymentRow } from './useAdminEntitlements'

const props = defineProps<{ payments: PaymentRow[] }>()
defineEmits<{ (e: 'open', id: number): void }>()

const rows = toRef(props, 'payments')

const STATUS_LABELS: Record<string, string> = {
	paid: t('libresign', 'Paid'),
	pending: t('libresign', 'Pending'),
	failed: t('libresign', 'Failed'),
	expired: t('libresign', 'Expired'),
	cancelled: t('libresign', 'Cancelled'),
	initiation_failed: t('libresign', 'Init failed'),
}
const PURPOSE_LABELS: Record<string, string> = {
	sign_request: t('libresign', 'Sign request'),
	credit_purchase: t('libresign', 'Credit purchase'),
	certificate_purchase: t('libresign', 'Certificate'),
}

const table = useLocalTable<PaymentRow>({
	rows,
	pageSize: 10,
	match: (p, q) => `${statusLabel(p.status)} ${purposeLabel(p.purpose)} ${p.provider} ${p.providerReference ?? ''}`.toLowerCase().includes(q),
})

function statusLabel(status: string): string { return STATUS_LABELS[status] ?? status }
function statusTone(status: string): string {
	if (status === 'paid') { return 'ok' }
	if (status === 'pending') { return 'warn' }
	return 'neutral'
}
function purposeLabel(purpose: string): string { return PURPOSE_LABELS[purpose] ?? purpose }

function formatDate(iso: string | null): string {
	if (!iso) { return '—' }
	const d = new Date(iso)
	return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' })
}
function formatAmount(p: PaymentRow): string {
	const minor = p.displayAmount ?? p.amount
	const currency = p.displayCurrency ?? p.currency
	return `${(minor / 100).toFixed(2)} ${currency}`
}
</script>

<style scoped lang="scss">
@use './admin-grants.scss';

.age-row-click {
	cursor: pointer;

	&:focus-visible {
		outline: 2px solid var(--age-focus);
		outline-offset: -2px;
	}
}
</style>
