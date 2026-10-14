<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="age-card">
		<header class="age-card__head">
			<div class="age-card__titles">
				<h3>{{ t('libresign', 'Document-signing credits') }}</h3>
				<span class="age-card__sub">{{ t('libresign', 'For {owner} · {code} · newest first', { owner: ownerLabel, code: product }) }}</span>
			</div>
		</header>

		<p class="age-balstrip">
			<span class="age-balstrip__big">{{ summary.availableUses }}</span>
			<span class="age-balstrip__k">{{ t('libresign', 'available') }}</span>
			<span class="age-balstrip__rest">
				<span class="age-balstrip__sep">·</span> {{ t('libresign', '{n} reserved', { n: summary.reservedUses }) }}
				<span class="age-balstrip__sep">·</span> {{ t('libresign', '{n} remaining', { n: summary.remainingUses }) }}
				<span class="age-balstrip__sep">·</span> {{ t('libresign', 'across {n} active', { n: summary.activeEntitlements }) }}
			</span>
		</p>

		<div v-if="!ordered.length" class="age-empty">{{ t('libresign', 'No signing-credit entitlements yet.') }}</div>

		<template v-else>
			<div class="age-led__pills" role="tablist" :aria-label="t('libresign', 'Filter by type')">
				<button type="button" class="age-led__pill" :aria-pressed="activeType === 'ALL'" @click="activeType = 'ALL'">
					{{ t('libresign', 'All') }} <span class="age-led__pilln">{{ typeCounts.ALL }}</span>
				</button>
				<button v-for="ty in typesPresent" :key="ty" type="button" class="age-led__pill" :aria-pressed="activeType === ty" @click="activeType = ty">
					<span class="age-led__dot" :class="typeCls(ty)" />{{ typeLabel(ty) }} <span class="age-led__pilln">{{ typeCounts[ty] }}</span>
				</button>
			</div>

			<div v-if="!byType.length" class="age-empty">{{ t('libresign', 'No {type} credits.', { type: typeLabel(activeType) }) }}</div>

			<template v-else>
				<div v-if="!table.filteredTotal.value" class="age-empty">{{ t('libresign', 'No active credits — open history below to see past buckets.') }}</div>

				<template v-else>
					<div class="age-tablewrap">
						<table class="age-table age-led">
							<thead>
								<tr>
									<th>{{ t('libresign', 'Added') }}</th>
									<th>{{ t('libresign', 'Type') }}</th>
									<th>{{ t('libresign', 'Balance') }}</th>
									<th>{{ t('libresign', 'Reserved') }}</th>
									<th>{{ t('libresign', 'Expiry') }}</th>
									<th>{{ t('libresign', 'Status') }}</th>
								</tr>
							</thead>
							<tbody>
								<template v-for="e in table.pageRows.value" :key="e.id">
									<tr :class="{ 'age-led__row--dim': !isLive(e), 'age-led__row--next': isNext(e) }">
										<td>
											<div class="age-num">{{ formatDate(e.createdAt) }}</div>
											<div class="age-mono age-muted age-led__id">#{{ e.id }}</div>
										</td>
										<td>
											<span class="age-led__type"><span class="age-led__dot" :class="typeCls(e.type)" />{{ typeLabel(e.type) }}</span>
										</td>
										<td>
											<div class="age-led__bal">
												<span class="age-num">{{ e.availableUses ?? '∞' }}</span>
												<span v-if="e.remainingUses !== null" class="age-led__bar" aria-hidden="true">
													<i class="is-avail" :style="{ width: pct(e.availableUses, e.remainingUses) }" />
													<i class="is-resv" :style="{ width: pct(e.reservedUses, e.remainingUses) }" />
												</span>
											</div>
											<div class="age-led__sub">{{ e.remainingUses === null ? t('libresign', 'unlimited') : t('libresign', 'of {n} remaining', { n: e.remainingUses }) }}</div>
										</td>
										<td class="age-figure">
											<button v-if="hasReservations(e)" type="button" class="age-resv-toggle" :aria-expanded="isOpen(e.id)" @click="toggle(e.id)">
												<span>{{ e.reservedUses }}</span>
												<svg viewBox="0 0 24 24" class="age-resv-toggle__chev" :class="{ 'age-resv-toggle__chev--open': isOpen(e.id) }" aria-hidden="true"><path d="M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z" /></svg>
											</button>
											<span v-else class="age-muted">{{ e.reservedUses || 0 }}</span>
										</td>
										<td class="age-muted">{{ relExpiry(e.expiresAt) }}</td>
										<td>
											<span v-if="isNext(e)" class="age-led__next">{{ t('libresign', 'Next') }}</span>
											<span class="age-chip" :class="statusCls(e)">{{ statusLabel(e) }}</span>
										</td>
									</tr>
									<tr v-if="isOpen(e.id)" class="age-subrow">
										<td colspan="6">
											<ul v-if="e.reservations && e.reservations.length" class="age-resv">
												<li v-for="(r, i) in e.reservations" :key="i" class="age-resv__item">
													<span class="age-chip" :class="r.sponsored ? 'age-chip--warn' : 'age-chip--neutral'">
														{{ r.sponsored ? t('libresign', 'Sponsoring') : t('libresign', 'Own signing') }}
													</span>
													<span v-if="r.sponsored" class="age-resv__who">{{ r.signerName || t('libresign', 'a signer') }}</span>
													<span class="age-resv__meta">
														{{ t('libresign', 'Sign request #{id}', { id: r.signRequestId }) }}
														· {{ t('libresign', '{n} credit(s)', { n: r.quantity }) }}
														· {{ formatDate(r.createdAt) }}
													</span>
												</li>
											</ul>
											<p v-else class="age-resv__empty">{{ t('libresign', 'No reservation detail available.') }}</p>
										</td>
									</tr>
								</template>
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

				<div v-if="historyRows.length" class="age-led__histfoot">
					<button type="button" class="age-led__histbtn" :aria-expanded="showHistory" @click="showHistory = !showHistory">
						<svg viewBox="0 0 24 24" class="age-led__chev" :class="{ 'age-led__chev--open': showHistory }" aria-hidden="true"><path d="M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z" /></svg>
						{{ showHistory ? t('libresign', 'Hide history') : t('libresign', 'Show history ({n})', { n: historyRows.length }) }}
					</button>
				</div>
			</template>
		</template>
	</section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import TablePager from './TablePager.vue'
import { useLocalTable } from './useLocalTable'
import type { EntitlementRow, EntitlementSummary } from './useAdminEntitlements'

const props = defineProps<{
	product: string
	ownerLabel: string
	summary: EntitlementSummary
	entitlements: EntitlementRow[]
}>()

const TYPE_META: Record<string, { label: string, cls: string }> = {
	PAY_AS_YOU_GO: { label: t('libresign', 'Pay as you go'), cls: 'is-payg' },
	SUBSCRIPTION: { label: t('libresign', 'Subscription'), cls: 'is-sub' },
	PROMOTIONAL: { label: t('libresign', 'Promotional'), cls: 'is-promo' },
	ADMIN_GRANTED: { label: t('libresign', 'Admin granted'), cls: 'is-admin' },
}
function typeLabel(ty: string): string { return ty === 'ALL' ? t('libresign', 'All') : (TYPE_META[ty]?.label ?? ty) }
function typeCls(ty: string): string { return TYPE_META[ty]?.cls ?? 'is-other' }

const activeType = ref<string>('ALL')
const showHistory = ref(false)
const open = ref<Set<number>>(new Set())

const now = Date.now()
function ts(iso: string | null): number { return iso ? new Date(iso).getTime() : 0 }
function isExpired(e: EntitlementRow): boolean { return !!e.expiresAt && ts(e.expiresAt) < now }
// Live = still has uses to draw and not expired (a fully-reserved bucket is still live).
function isLive(e: EntitlementRow): boolean { return !isExpired(e) && (e.remainingUses === null || (e.remainingUses ?? 0) > 0) }

const ordered = computed(() => [...props.entitlements].sort((a, b) => ts(b.createdAt) - ts(a.createdAt)))

const typeCounts = computed<Record<string, number>>(() => {
	const c: Record<string, number> = { ALL: props.entitlements.length }
	for (const e of props.entitlements) { c[e.type] = (c[e.type] || 0) + 1 }
	return c
})
const typesPresent = computed(() => Object.keys(TYPE_META).filter((k) => (typeCounts.value[k] || 0) > 0))

const byType = computed(() => activeType.value === 'ALL' ? ordered.value : ordered.value.filter((e) => e.type === activeType.value))
const liveRows = computed(() => byType.value.filter(isLive))
const historyRows = computed(() => byType.value.filter((e) => !isLive(e)))

// Live rows first; history appended only when revealed. The pager (below) pages
// over this set, and useLocalTable resets to page 1 whenever it changes (type
// switch, history toggle).
const visibleRows = computed<readonly EntitlementRow[]>(() => showHistory.value ? [...liveRows.value, ...historyRows.value] : liveRows.value)

const table = useLocalTable<EntitlementRow>({ rows: visibleRows, pageSize: 10 })

function isNext(e: EntitlementRow): boolean { return props.summary.nextEntitlementId != null && e.id === props.summary.nextEntitlementId }

function pct(part: number | null, total: number | null): string {
	if (!total || total <= 0 || part == null) { return '0%' }
	return `${Math.max(0, Math.min(100, (part / total) * 100))}%`
}

function statusLabel(e: EntitlementRow): string {
	if (isExpired(e)) { return t('libresign', 'Expired') }
	if ((e.remainingUses ?? 1) <= 0) { return t('libresign', 'Depleted') }
	if ((e.availableUses ?? 1) <= 0 && (e.reservedUses ?? 0) > 0) { return t('libresign', 'Reserved') }
	return t('libresign', 'Active')
}
function statusCls(e: EntitlementRow): string {
	if (isExpired(e)) { return 'age-chip--err' }
	if ((e.remainingUses ?? 1) <= 0) { return 'age-chip--neutral' }
	if ((e.availableUses ?? 1) <= 0 && (e.reservedUses ?? 0) > 0) { return 'age-chip--warn' }
	return 'age-chip--ok'
}

function toggle(id: number) {
	const next = new Set(open.value)
	if (next.has(id)) { next.delete(id) } else { next.add(id) }
	open.value = next
}
function isOpen(id: number): boolean { return open.value.has(id) }
function hasReservations(e: EntitlementRow): boolean { return (e.reservedUses ?? 0) > 0 }

function formatDate(iso: string | null): string {
	if (!iso) { return '—' }
	const d = new Date(iso)
	return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' })
}
function relExpiry(iso: string | null): string {
	if (!iso) { return t('libresign', 'No expiry') }
	const d = new Date(iso)
	if (Number.isNaN(d.getTime())) { return '—' }
	const days = Math.round((d.getTime() - now) / 86400000)
	if (days < 0) { return t('libresign', 'Expired {n}d ago', { n: Math.abs(days) }) }
	if (days === 0) { return t('libresign', 'Expires today') }
	if (days <= 45) { return t('libresign', 'Expires in {n}d', { n: days }) }
	return t('libresign', 'Expires {date}', { date: formatDate(iso) })
}
</script>

<style scoped lang="scss">
@use './admin-grants.scss';

/* Expired chip not in the shared sheet; uses the err tokens from the mixin. */
.age-chip--err { background: var(--age-err-bg, rgba(220, 53, 69, 0.15)); color: var(--age-err-text, #c82333); }

/* compact balance strip (replaces the three summary tiles) */
.age-balstrip {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 5px;
	margin: 0;
	padding: 2px 0 14px;
	font-size: 13px;
	color: var(--age-fg-muted);

	&__big {
		font-size: 22px;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		line-height: 1;
		color: var(--age-green-text, #0a6636);
	}
	&__k { color: var(--age-fg); font-weight: 600; margin-inline-end: 3px; }
	&__rest { color: var(--age-fg-muted); font-variant-numeric: tabular-nums; }
	&__sep { color: var(--age-fg-faint); margin: 0 2px; }
}

/* type filter pills (scrollable) */
.age-led__pills {
	display: flex;
	gap: 8px;
	overflow-x: auto;
	padding: 2px 0 10px;
	scrollbar-width: thin;

	&::-webkit-scrollbar { height: 6px; }
	&::-webkit-scrollbar-thumb { background: var(--age-border-strong); border-radius: 3px; }
}
.age-led__pill {
	flex: none;
	display: inline-flex;
	align-items: center;
	gap: 7px;
	border: 1px solid var(--age-border);
	background: var(--age-surface);
	color: var(--age-fg-muted);
	font: inherit;
	font-size: 12.5px;
	font-weight: 600;
	padding: 6px 13px;
	border-radius: 999px;
	cursor: pointer;
	white-space: nowrap;

	&:hover { color: var(--age-fg); }
	&[aria-pressed='true'] { background: var(--age-fg); color: var(--age-surface); border-color: var(--age-fg); }
	&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 2px; }
}
.age-led__pilln { font-size: 11px; opacity: 0.75; font-variant-numeric: tabular-nums; }

.age-led__dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.is-payg { background: #3b82f6; }
.is-sub { background: #8b5cf6; }
.is-promo { background: #06b6d4; }
.is-admin { background: var(--age-green, #04d56d); }
.is-other { background: var(--age-fg-faint); }

.age-led {
	&__type { display: inline-flex; align-items: center; gap: 8px; }
	&__id { font-size: 11px; margin-top: 2px; }

	&__bal { display: flex; align-items: center; gap: 8px; }
	&__bar {
		display: flex;
		height: 5px;
		min-width: 54px;
		flex: 1;
		max-width: 110px;
		border-radius: 999px;
		background: var(--age-neutral-bg);
		overflow: hidden;

		i { display: block; height: 100%; }
		.is-avail { background: var(--age-green, #04d56d); }
		.is-resv { background: var(--age-warn-text); }
	}
	&__sub { font-size: 11px; color: var(--age-fg-faint); margin-top: 3px; }

	&__next {
		display: inline-block;
		font-size: 10.5px;
		font-weight: 700;
		padding: 2px 7px;
		border-radius: 999px;
		margin-inline-end: 6px;
		background: var(--age-green, #04d56d);
		color: #06351f;
	}

	&__row--next td { background: var(--age-green-dim, rgba(4, 213, 109, 0.12)); }
	&__row--next td:first-child { box-shadow: inset 3px 0 0 var(--age-green, #04d56d); }
	&__row--dim td { opacity: 0.56; }

	/* history toggle — last item on the card */
	&__histfoot {
		margin-top: 4px;
		padding-top: 10px;
		border-top: 1px solid var(--age-border);
	}
	&__histbtn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		border: none;
		background: none;
		color: var(--age-fg-muted);
		font: inherit;
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		padding: 4px 2px;

		&:hover { color: var(--age-fg); }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 2px; border-radius: 4px; }
	}
	&__chev {
		width: 15px;
		height: 15px;
		fill: var(--age-fg-faint);
		transition: transform 0.15s ease;
		&--open { transform: rotate(180deg); }
	}
}

/* reserved expand (carried from the previous table) */
.age-figure { font-variant-numeric: tabular-nums; }

.age-resv-toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	border: none;
	background: transparent;
	color: var(--age-fg);
	font: inherit;
	font-variant-numeric: tabular-nums;
	cursor: pointer;
	padding: 2px 4px;
	border-radius: 6px;

	&:hover { background: var(--age-surface-2); }
	&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 1px; }

	&__chev {
		width: 15px;
		height: 15px;
		fill: var(--age-fg-muted);
		transition: transform 0.15s ease;
		&--open { transform: rotate(180deg); }
	}
}

.age-subrow > td { background: var(--age-surface-2); padding: 10px 14px; }

.age-resv {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__item { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 12.5px; }
	&__who { font-weight: 600; color: var(--age-fg); }
	&__meta { color: var(--age-fg-muted); font-variant-numeric: tabular-nums; }
	&__empty { margin: 0; font-size: 12.5px; color: var(--age-fg-faint); }
}

@media (prefers-reduced-motion: reduce) {
	.age-resv-toggle__chev,
	.age-led__chev { transition: none; }
}
</style>
