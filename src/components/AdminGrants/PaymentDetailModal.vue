<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog v-if="modelValue" :name="t('libresign', 'Payment details')" size="large" @update:open="onOpenChange">
		<div class="pdm">
			<div v-if="loading" class="pdm__state">
				<span class="pdm__spinner" aria-hidden="true" />
				<span>{{ t('libresign', 'Loading payment…') }}</span>
			</div>

			<div v-else-if="error" class="pdm__state pdm__state--error">{{ error }}</div>

			<template v-else-if="detail">
				<div class="pdm__head">
					<span class="pdm__chip" :class="'pdm__chip--' + statusTone(detail.summary.status)">{{ labelStatus(detail.summary.status) }}</span>
					<span class="pdm__amount">{{ money(detail.summary.amount, detail.summary.currency) }}</span>
					<span class="pdm__sep">·</span>
					<span class="pdm__provider">{{ detail.summary.provider }}</span>
					<span class="pdm__sep">·</span>
					<span class="pdm__purpose">{{ humanize(detail.summary.purpose) }}</span>
					<span class="pdm__spacer" />
					<span class="pdm__num">#{{ detail.summary.id }}</span>
				</div>

				<div class="pdm__outcome" :class="'pdm__outcome--' + outcomeTone">
					<span class="pdm__outcome-ic">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="outcomeIconPath" /></svg>
					</span>
					<div class="pdm__outcome-main">
						<div class="pdm__outcome-h">{{ outcomeCopy.headline }}</div>
						<div class="pdm__outcome-s">
							<span v-if="causeCode" class="pdm__code">{{ causeCode }}</span>
							{{ causeText }}
						</div>
						<div v-if="outcomeCopy.guidance" class="pdm__guidance">{{ outcomeCopy.guidance }}</div>
					</div>
				</div>

				<!-- Custom segmented tabs (borrowed from PaymentStep .method-toggle) -->
				<div class="pdm__toggle" role="tablist">
					<button v-for="tb in tabs"
						:key="tb.id"
						type="button"
						role="tab"
						:aria-selected="activeTab === tb.id"
						class="pdm__tab"
						:class="{ active: activeTab === tb.id }"
						@click="activeTab = tb.id">
						<svg class="pdm__tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path :d="tb.icon" /></svg>
						{{ tb.label }}
					</button>
				</div>

				<div class="pdm__body">
					<Transition name="tab-switch" mode="out-in">
						<section :key="activeTab" class="pdm__pane">
							<!-- SUMMARY -->
							<template v-if="activeTab === 'summary'">
								<dl class="pdm__meta">
									<div class="pdm__cell pdm__cell--wide">
										<dt>{{ t('libresign', 'Purchase') }}</dt>
										<dd>{{ purchaseLine }}</dd>
									</div>
									<div v-for="f in summaryFields" :key="f.label" class="pdm__cell">
										<dt>{{ f.label }}</dt>
										<dd :class="{ 'pdm__mono': f.mono }">{{ dash(f.value) }}</dd>
									</div>
									<div class="pdm__cell pdm__cell--wide">
										<dt>{{ t('libresign', 'Attempt ID') }}</dt>
										<dd class="pdm__mono pdm__attempt">{{ detail.summary.attemptId }}</dd>
									</div>
									<div class="pdm__cell pdm__cell--wide">
										<dt>{{ t('libresign', 'Phone') }}</dt>
										<dd class="pdm__phone">
											<span class="pdm__mono">{{ phoneShown }}</span>
											<button v-if="detail.summary.phone" type="button" class="pdm__reveal" @click="phoneRevealed = !phoneRevealed">
												<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="phoneRevealed ? mdiEyeOffOutline : mdiEyeOutline" /></svg>
												{{ phoneRevealed ? t('libresign', 'Hide') : t('libresign', 'Reveal') }}
											</button>
										</dd>
									</div>
								</dl>

								<div class="pdm__divider" />

								<h4 class="pdm__sectitle">{{ t('libresign', 'What happened') }}</h4>
								<div class="pdm__tl">
									<div v-for="stage in stageOrder" :key="stage" class="pdm__tl-item">
										<span class="pdm__tl-mark" :class="'pdm__tl-mark--' + stageState(stage)">
											<svg v-if="stageState(stage) === 'ok'" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCheck" /></svg>
											<svg v-else-if="stageState(stage) === 'err'" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiExclamationThick" /></svg>
										</span>
										<div class="pdm__tl-body">
											<div class="pdm__tl-head">
												<span class="pdm__tl-name">{{ humanize(stage) }}</span>
												<span v-if="stageState(stage) === 'skip'" class="pdm__chip pdm__chip--neutral">{{ t('libresign', 'Not recorded') }}</span>
											</div>
											<div v-if="stageLine(stage).text || stageLine(stage).code" class="pdm__tl-codes">
												<span v-if="stageLine(stage).code" class="pdm__code">{{ stageLine(stage).code }}</span>
												{{ stageLine(stage).text }}
											</div>
										</div>
									</div>
								</div>

								<div v-if="detail.diagnostic.stateMismatch" class="pdm__diag">
									<svg class="pdm__diag-ic" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiAlertOutline" /></svg>
									<span>{{ t('libresign', 'Execution state still reads “executing” while a terminal status is recorded — the orchestrator logged the outcome but did not advance the state. Trust the status and provider error, not the execution state.') }}</span>
								</div>
							</template>

							<!-- PROVIDER -->
							<template v-else-if="activeTab === 'provider'">
								<div class="pdm__prov">
									<span class="pdm__prov-badge">{{ detail.summary.provider }}</span>
									<span class="pdm__chip" :class="'pdm__chip--' + outcomeTone">{{ providerStateLabel }}</span>
									<span v-if="providerRouting" class="pdm__prov-route">{{ providerRouting }}</span>
								</div>
								<dl class="pdm__grid">
									<div v-for="f in providerFields" :key="f.label" class="pdm__field" :class="{ 'pdm__field--wide': f.wide }">
										<dt>{{ f.label }}</dt>
										<dd :class="{ 'pdm__mono': f.mono }">{{ dash(f.value) }}</dd>
									</div>
								</dl>
							</template>

							<!-- RAW -->
							<template v-else>
								<div class="pdm__rawhead">
									<h4 class="pdm__sectitle">{{ t('libresign', 'provider_metadata (verbatim, phone masked)') }}</h4>
									<button v-if="detail.row" type="button" class="pdm__copy" :class="{ 'is-copied': copied }" @click="copyRow">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="copied ? mdiCheck : mdiContentCopy" /></svg>
										{{ copied ? t('libresign', 'Copied') : t('libresign', 'Copy full row') }}
									</button>
								</div>
								<pre v-if="rawJson" class="pdm__raw">{{ rawJson }}</pre>
								<p v-else class="pdm__muted">{{ t('libresign', 'No provider metadata recorded.') }}</p>

								<template v-if="detail.diagnostic.providerError">
									<h4 class="pdm__sectitle">{{ t('libresign', 'providerError') }}</h4>
									<dl class="pdm__grid">
										<div v-for="f in providerErrorFields" :key="f.label" class="pdm__field">
											<dt>{{ f.label }}</dt>
											<dd :class="{ 'pdm__mono': f.mono }">{{ dash(f.value) }}</dd>
										</div>
									</dl>
								</template>

								<div v-if="detail.diagnostic.legacyError" class="pdm__diag pdm__diag--neutral">
									<strong>{{ t('libresign', 'Recorded error') }}:</strong>
									{{ detail.diagnostic.legacyError.message || detail.diagnostic.legacyError.code || t('libresign', 'No message recorded') }}
									<template v-if="detail.diagnostic.legacyError.at"> · {{ fmt(detail.diagnostic.legacyError.at) }}</template>
								</div>

								<div class="pdm__note">
									{{ t('libresign', 'Read-only. Persisted status') }} <strong>{{ labelStatus(detail.finalState.status) }}</strong>. {{ detail.finalState.note }}
								</div>
							</template>
						</section>
					</Transition>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircleOutline,
	mdiAlertOutline,
	mdiCheck,
	mdiCodeJson,
	mdiContentCopy,
	mdiExclamationThick,
	mdiEyeOffOutline,
	mdiEyeOutline,
	mdiReceiptTextOutline,
	mdiServerNetworkOutline,
} from '@mdi/js'

import NcDialog from '@nextcloud/vue/components/NcDialog'

import { useAdminEntitlements, ocsError, type PaymentDetail, type LifecycleStage } from './useAdminEntitlements'

interface FieldRow { label: string, value: string | null, mono?: boolean, wide?: boolean }
type StageKey = 'initiation' | 'charge' | 'callback' | 'query' | 'verification'

const props = defineProps<{
	modelValue: boolean
	paymentId: number | null
}>()
const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>()

const { getPaymentDetail } = useAdminEntitlements()

const detail = ref<PaymentDetail | null>(null)
const loading = ref(false)
const error = ref('')
const phoneRevealed = ref(false)
const activeTab = ref<'summary' | 'provider' | 'raw'>('summary')

const stageOrder: StageKey[] = ['initiation', 'charge', 'callback', 'query', 'verification']

const tabs = computed(() => [
	{ id: 'summary' as const, label: t('libresign', 'Summary'), icon: mdiReceiptTextOutline },
	{ id: 'provider' as const, label: t('libresign', 'Provider'), icon: mdiServerNetworkOutline },
	{ id: 'raw' as const, label: t('libresign', 'Raw'), icon: mdiCodeJson },
])

const REASON_COPY: Record<string, { headline: string, guidance: string }> = {
	success: { headline: t('libresign', 'Payment successful'), guidance: '' },
	cancelled: {
		headline: t('libresign', 'Cancelled by customer'),
		guidance: t('libresign', 'They dismissed or declined the M-Pesa prompt. No money was taken — ask them to retry and approve the push.'),
	},
	wrong_pin: {
		headline: t('libresign', 'Wrong M-Pesa PIN'),
		guidance: t('libresign', 'The customer entered an invalid PIN. No money was taken — ask them to retry carefully.'),
	},
	timeout: {
		headline: t('libresign', 'No response from customer'),
		guidance: t('libresign', 'The prompt timed out before a PIN was entered. No money was taken — ask them to keep their phone unlocked and retry.'),
	},
	expired: {
		headline: t('libresign', 'Request expired'),
		guidance: t('libresign', 'The request lapsed before the provider recorded any response. No money was taken.'),
	},
	insufficient_balance: {
		headline: t('libresign', 'Insufficient funds'),
		guidance: t('libresign', 'The M-Pesa balance was too low to complete the charge. No money was taken.'),
	},
	transaction_in_progress: {
		headline: t('libresign', 'Another transaction in progress'),
		guidance: t('libresign', 'M-Pesa was still processing a previous request. No money was taken — ask them to wait a moment and retry.'),
	},
	provider_error: {
		headline: t('libresign', 'Provider error'),
		guidance: t('libresign', 'The provider reported an internal error. No money was taken — the customer can retry.'),
	},
	invalid_request: {
		headline: t('libresign', 'Invalid request'),
		guidance: t('libresign', 'The provider rejected the request before any charge. No money was taken.'),
	},
	generic_failure: {
		headline: t('libresign', 'Payment failed'),
		guidance: t('libresign', 'The payment did not complete. No money was taken — the customer can retry.'),
	},
	initiation_failed: {
		headline: t('libresign', 'Payment could not start'),
		guidance: t('libresign', 'The payment did not execute — it failed before the provider was contacted. No money was taken; the customer can retry.'),
	},
}
const FALLBACK_COPY = { headline: t('libresign', 'Payment failed'), guidance: t('libresign', 'The payment did not complete. No money was taken — the customer can retry.') }

const outcomeCopy = computed(() => {
	const o = detail.value?.outcome
	if (!o) {
		return FALLBACK_COPY
	}
	if (o.kind === 'success') {
		return REASON_COPY.success
	}
	return (o.reason && REASON_COPY[o.reason]) ? REASON_COPY[o.reason] : FALLBACK_COPY
})

const outcomeTone = computed(() => detail.value?.outcome.severity ?? 'error')
const outcomeIconPath = computed(() => {
	const sev = outcomeTone.value
	return sev === 'ok' ? mdiCheck : (sev === 'warn' ? mdiAlertOutline : mdiAlertCircleOutline)
})

const causeCode = computed(() => {
	const o = detail.value?.outcome
	if (!o || o.kind === 'success') {
		return ''
	}
	return o.resultCode != null ? `ResultCode ${o.resultCode}` : ''
})
const causeText = computed(() => {
	const o = detail.value?.outcome
	if (!o) {
		return ''
	}
	if (o.kind === 'success') {
		const receipt = detail.value?.identifiers.mpesaReceipt
		const verif = detail.value?.verification.status
		if (detail.value?.summary.provider === 'dpo' && !receipt) {
			return t('libresign', 'Settled via DPO.') + (verif ? ` ${t('libresign', 'Verification')} ${verif}.` : '')
		}
		const parts: string[] = []
		if (receipt) {
			parts.push(`${t('libresign', 'M-Pesa receipt')} ${receipt}`)
		}
		if (verif) {
			parts.push(`${t('libresign', 'Verification')} ${verif}`)
		}
		return parts.join(' · ') + (parts.length ? '.' : '')
	}
	const desc = o.resultDesc || (o.resultCode == null ? t('libresign', 'provider returned no final response') : '')
	return [desc, sourceLabel(o.source)].filter(Boolean).join(' · ')
})

function sourceLabel(source: string | null): string {
	switch (source) {
	case 'daraja_query': return t('libresign', 'detected during status query')
	case 'daraja_callback': return t('libresign', 'reported via callback')
	case null: return t('libresign', 'no provider response recorded')
	default: return source ? t('libresign', 'reported by {source}', { source }) : ''
	}
}

const purchaseLine = computed(() => {
	const s = detail.value?.summary
	if (!s) {
		return ''
	}
	const units = `${s.quantity} ${s.quantity === 1 ? t('libresign', 'unit') : t('libresign', 'units')}`
	return t('libresign', 'Bought {units} for {total} ({each} each)', {
		units,
		total: money(s.amount, s.currency),
		each: money(s.unitAmount, s.currency),
	})
})

function digitsOf(v: string | null): string {
	return (v || '').replace(/\D/g, '')
}
// Kenyan E.164 (+254 + 9 digits) grouped 3-3-3; other formats pass through.
function formatPhone(e164: string | null): string | null {
	if (!e164) {
		return null
	}
	const d = digitsOf(e164)
	if (d.startsWith('254') && d.length === 12) {
		const n = d.slice(3)
		return `+254 ${n.slice(0, 3)} ${n.slice(3, 6)} ${n.slice(6, 9)}`
	}
	return e164
}
function maskFormatPhone(full: string | null, fallback: string | null): string {
	const d = digitsOf(full)
	if (d.startsWith('254') && d.length === 12) {
		return `+254 ••• ••• ${d.slice(9, 12)}`
	}
	return fallback || '—'
}

const phoneShown = computed(() => {
	const s = detail.value?.summary
	if (!s) {
		return '—'
	}
	if (phoneRevealed.value && s.phone) {
		return formatPhone(s.phone) || s.phone
	}
	return maskFormatPhone(s.phone, s.phoneMasked)
})

const copied = ref(false)
async function copyRow() {
	const row = detail.value?.row
	if (!row) {
		return
	}
	try {
		await navigator.clipboard.writeText(JSON.stringify(row, null, 2))
		copied.value = true
		window.setTimeout(() => { copied.value = false }, 1600)
	} catch {
		/* clipboard unavailable */
	}
}

const summaryFields = computed<FieldRow[]>(() => {
	const d = detail.value
	if (!d) {
		return []
	}
	const s = d.summary
	return [
		{ label: t('libresign', 'Credits'), value: String(s.productUses) },
		{ label: t('libresign', 'Product'), value: s.productCode },
		{ label: t('libresign', 'Created'), value: fmt(s.createdAt) },
		{ label: s.paidAt ? t('libresign', 'Paid') : t('libresign', 'Last update'), value: fmt(s.paidAt || s.updatedAt) },
	]
})

const providerStateLabel = computed(() => {
	const o = detail.value?.outcome
	if (!o) {
		return ''
	}
	return o.kind === 'success' ? t('libresign', 'success') : (o.reason ? humanize(o.reason) || o.reason : t('libresign', 'failed'))
})
const providerRouting = computed(() => {
	const i = detail.value?.identifiers
	if (!i || !i.preferredProvider) {
		return ''
	}
	return i.preferredProvider === i.executedProvider
		? t('libresign', 'preferred = executed')
		: `${i.preferredProvider} → ${i.executedProvider}`
})

const providerFields = computed<FieldRow[]>(() => {
	const d = detail.value
	if (!d) {
		return []
	}
	const i = d.identifiers
	const rows: FieldRow[] = [
		{ label: t('libresign', 'Provider reference'), value: i.providerReference, mono: true, wide: true },
	]
	if (d.summary.provider === 'dpo') {
		rows.push(
			{ label: t('libresign', 'Transaction token'), value: i.dpoTransactionToken, mono: true, wide: true },
			{ label: t('libresign', 'Transaction ref'), value: i.dpoTransactionRef, mono: true },
		)
	} else {
		rows.push(
			{ label: t('libresign', 'Checkout request'), value: i.checkoutRequestId, mono: true, wide: true },
			{ label: t('libresign', 'Merchant request'), value: i.merchantRequestId, mono: true, wide: true },
		)
		if (i.mpesaReceipt) {
			rows.push({ label: t('libresign', 'M-Pesa receipt'), value: i.mpesaReceipt, mono: true })
		}
	}
	if (i.transactionId != null || i.transactionReference) {
		rows.push({ label: t('libresign', 'Sign request / ref'), value: joinDash(i.transactionId != null ? String(i.transactionId) : null, i.transactionReference), mono: true, wide: true })
	}
	return rows
})

const providerErrorFields = computed<FieldRow[]>(() => {
	const e = detail.value?.diagnostic.providerError
	if (!e) {
		return []
	}
	const rows: FieldRow[] = []
	const push = (label: string, value: string | null, mono = false) => {
		if (value != null && value !== '') {
			rows.push({ label, value, mono })
		}
	}
	push(t('libresign', 'type'), e.type)
	push(t('libresign', 'reason'), e.reason)
	push(t('libresign', 'source'), e.source)
	push(t('libresign', 'resultCode'), e.resultCode != null ? String(e.resultCode) : null)
	push(t('libresign', 'resultDesc'), e.resultDesc)
	push(t('libresign', 'timestamp'), e.timestamp ? fmt(e.timestamp) : null)
	push(t('libresign', 'message'), e.message)
	push(t('libresign', 'exception'), e.exception, true)
	push(t('libresign', 'code'), e.code)
	return rows
})

const rawJson = computed(() => {
	const raw = detail.value?.raw
	if (!raw) {
		return ''
	}
	try {
		return JSON.stringify(raw, null, 2)
	} catch {
		return ''
	}
})

function stageState(stage: StageKey): 'ok' | 'err' | 'empty' | 'skip' {
	const o = detail.value?.outcome
	if (o && o.kind === 'failure' && o.stageFailed === stage) {
		return 'err'
	}
	const s = detail.value?.lifecycle[stage] ?? null
	if (s === null) {
		return 'skip'
	}
	return Object.keys(s.fields).length ? 'ok' : 'empty'
}

function stageLine(stage: StageKey): { code: string, text: string } {
	const s: LifecycleStage | null = detail.value?.lifecycle[stage] ?? null
	if (s === null) {
		return { code: '', text: '' }
	}
	const f = s.fields
	const pick = (...keys: string[]): string => {
		for (const k of keys) {
			const hit = Object.keys(f).find((kk) => kk.toLowerCase() === k.toLowerCase())
			if (hit && f[hit] !== '') {
				return f[hit]
			}
		}
		return ''
	}
	const code = pick('ResultCode', 'ResponseCode', 'Result', 'StatusCode')
	const text = pick('ResultDesc', 'ResultExplanation', 'ResponseDescription', 'CustomerMessage', 'description', 'status')
	const receipt = pick('MpesaReceiptNumber')
	const codeLabel = code !== '' ? (stage === 'verification' ? code : `${codeKey(f)} ${code}`) : ''
	return { code: codeLabel, text: [text, receipt ? `${t('libresign', 'receipt')} ${receipt}` : ''].filter(Boolean).join(' · ') }
}
function codeKey(f: Record<string, string>): string {
	const has = (k: string) => Object.keys(f).some((kk) => kk.toLowerCase() === k.toLowerCase())
	if (has('ResultCode')) {
		return 'ResultCode'
	}
	if (has('ResponseCode')) {
		return 'ResponseCode'
	}
	if (has('Result')) {
		return 'Result'
	}
	if (has('StatusCode')) {
		return 'StatusCode'
	}
	return ''
}

watch(() => [props.modelValue, props.paymentId] as const, ([open, id]) => {
	if (open && typeof id === 'number') {
		load(id)
	}
}, { immediate: true })

async function load(id: number) {
	loading.value = true
	error.value = ''
	detail.value = null
	phoneRevealed.value = false
	activeTab.value = 'summary'
	try {
		detail.value = await getPaymentDetail(id)
	} catch (err) {
		error.value = ocsError(err, t('libresign', 'Could not load payment details.'))
	} finally {
		loading.value = false
	}
}

function onOpenChange(open: boolean) {
	if (!open) {
		emit('update:modelValue', false)
	}
}

function dash(v: string | null): string { return v && v !== '' ? v : '—' }
function fmt(iso: string | null): string {
	if (!iso) {
		return '—'
	}
	const d = new Date(iso)
	return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString()
}
function money(minor: number, currency: string): string {
	return `${(minor / 100).toFixed(2)} ${currency}`
}
function joinDash(a: string | null, b: string | null): string | null {
	const parts = [a, b].filter((x) => x)
	return parts.length ? parts.join(' · ') : null
}
function humanize(v: string | null): string | null {
	return v ? v.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : null
}

const STATUS_LABELS: Record<string, string> = {
	paid: t('libresign', 'Paid'),
	pending: t('libresign', 'Pending'),
	failed: t('libresign', 'Failed'),
	expired: t('libresign', 'Expired'),
	cancelled: t('libresign', 'Cancelled'),
	initiation_failed: t('libresign', 'Init failed'),
}
function labelStatus(s: string): string { return STATUS_LABELS[s] ?? s }
function statusTone(s: string): string {
	if (s === 'paid') {
		return 'ok'
	}
	if (s === 'cancelled' || s === 'expired' || s === 'pending') {
		return 'warn'
	}
	if (s === 'failed' || s === 'initiation_failed') {
		return 'err'
	}
	return 'neutral'
}
</script>

<style scoped lang="scss">
@use './tokens' as agtokens;

.pdm {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px 22px;
	color: var(--age-fg);
	text-align: left; // NcDialog content centres text; our panel is left-aligned

	// All inline @mdi/js icons inherit colour (no NcIconSvgWrapper).
	svg { fill: currentColor; display: block; }

	&__state {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 30px 0;
		color: var(--age-fg-muted);
		font-size: 14px;

		&--error { color: var(--age-danger); }
	}

	&__spinner {
		width: 22px;
		height: 22px;
		border-radius: 50%;
		border: 2.5px solid var(--age-border);
		border-top-color: var(--age-green);
		animation: pdm-spin 0.7s linear infinite;
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
		font-size: 14px;
	}

	&__amount { font-weight: 600; font-variant-numeric: tabular-nums; font-size: 18px; }
	&__sep { color: var(--age-fg-faint); }
	&__provider { text-transform: capitalize; }
	&__purpose { color: var(--age-fg-muted); }
	&__spacer { flex: 1 1 auto; }
	&__num { font-family: var(--age-mono); font-size: 12px; color: var(--age-fg-faint); }

	&__chip {
		display: inline-flex;
		align-items: center;
		font-size: 11.5px;
		font-weight: 600;
		padding: 3px 9px;
		border-radius: 999px;
		white-space: nowrap;

		&--ok { background: var(--age-green-dim); color: var(--age-green-text); }
		&--warn { background: var(--age-warn-bg); color: var(--age-warn-text); }
		&--err { background: var(--age-err-bg); color: var(--age-err-text); }
		&--neutral { background: var(--age-neutral-bg); color: var(--age-neutral-text); }
	}

	/* Outcome banner */
	&__outcome {
		display: flex;
		gap: 12px;
		border-radius: var(--age-radius-sm);
		padding: 13px 15px;

		&--ok { background: var(--age-green-dim); }
		&--warn { background: var(--age-warn-bg); }
		&--err { background: var(--age-err-bg); }
	}

	&__outcome-ic {
		flex: none;
		width: 26px;
		height: 26px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		color: #fff;

		svg { width: 16px; height: 16px; }

		.pdm__outcome--ok & { background: var(--age-green); }
		.pdm__outcome--warn & { background: var(--age-warn-text); }
		.pdm__outcome--err & { background: var(--age-err-text); }
	}

	&__outcome-main { min-width: 0; }
	&__outcome-h { font-weight: 600; font-size: 14.5px; margin-bottom: 2px; }
	&__outcome-s { font-size: 12.5px; color: var(--age-fg-muted); }

	&__guidance {
		margin-top: 8px;
		font-size: 12.5px;
		color: var(--age-fg);
		background: var(--age-surface);
		border: 1px solid var(--age-border);
		border-radius: 7px;
		padding: 8px 10px;
	}

	&__code {
		display: inline-block;
		font-family: var(--age-mono);
		font-size: 11.5px;
		background: var(--age-surface);
		border: 1px solid var(--age-border);
		border-radius: 6px;
		padding: 1px 7px;
	}

	/* Segmented tabs (PaymentStep .method-toggle) */
	&__toggle {
		display: flex;
		gap: 4px;
		padding: 4px;
		background: var(--age-surface-2);
		border-radius: var(--age-radius);
	}

	&__tab {
		flex: 1 1 0;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 7px;
		padding: 8px 12px;
		border: none;
		border-radius: 8px;
		background: transparent;
		color: var(--age-fg-muted);
		font: inherit;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		transition: color 180ms cubic-bezier(0.4, 0, 0.2, 1), background 180ms cubic-bezier(0.4, 0, 0.2, 1);

		.pdm__tab-ic { width: 16px; height: 16px; color: var(--age-fg-faint); transition: color 180ms; }

		&:hover:not(.active) {
			color: var(--age-fg);
			background: var(--age-neutral-bg);
		}

		&.active {
			background: var(--age-surface);
			color: var(--age-fg);
			box-shadow: 0 1px 2px rgba(0, 0, 0, 0.09);

			.pdm__tab-ic { color: var(--age-green); }
		}

		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 2px; }
	}

	&__body { min-height: 120px; }
	&__pane { display: flex; flex-direction: column; gap: 14px; }

	&__sectitle {
		margin: 4px 0 0;
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.06em;
		color: var(--age-fg-faint);
		font-weight: 600;
	}

	/* Summary meta grid: stacked caption + value, long fields full-width */
	&__meta {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 11px 28px;
		margin: 0;
	}

	&__cell {
		min-width: 0;
		display: flex;
		align-items: baseline;
		gap: 7px;

		&--wide { grid-column: 1 / -1; }

		dt {
			flex: none;
			font-size: 12.5px;
			color: var(--age-fg-muted);
			font-weight: 500;

			&::after { content: ':'; }
		}
		dd { margin: 0; min-width: 0; font-size: 13px; line-height: 1.45; color: var(--age-fg); word-break: break-word; }
	}

	&__attempt {
		display: inline-block;
		background: var(--age-surface-2);
		border: 1px solid var(--age-border);
		border-radius: 6px;
		padding: 3px 8px;
	}

	&__rawhead {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__copy {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		flex: none;
		border: 1px solid var(--age-border-strong);
		background: var(--age-surface);
		color: var(--age-fg-muted);
		font: inherit;
		font-size: 11.5px;
		font-weight: 600;
		padding: 4px 10px;
		border-radius: 7px;
		cursor: pointer;

		svg { width: 13px; height: 13px; }
		&:hover { color: var(--age-fg); border-color: var(--age-green-border); }
		&.is-copied { color: var(--age-green-text); border-color: var(--age-green-border); background: var(--age-green-dim); }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 1px; }
	}

	&__divider { height: 1px; background: var(--age-border); margin: 4px 0 2px; }

	&__grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px 20px;
		margin: 0;
	}

	&__field {
		min-width: 0;

		&--wide { grid-column: 1 / -1; }
		dt { font-size: 11.5px; color: var(--age-fg-faint); margin-bottom: 2px; }
		dd { margin: 0; font-size: 13px; color: var(--age-fg); word-break: break-word; }
	}

	&__mono { font-family: var(--age-mono); font-size: 12px; word-break: break-all; }
	&__muted { margin: 0; font-size: 12.5px; color: var(--age-fg-faint); }

	&__phone { display: inline-flex; align-items: center; gap: 2px; }
	&__reveal {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		margin-inline-start: 8px;
		border: 1px solid var(--age-border-strong);
		background: var(--age-surface);
		color: var(--age-fg-muted);
		font-size: 11px;
		padding: 2px 9px;
		border-radius: 999px;
		cursor: pointer;

		svg { width: 13px; height: 13px; }
		&:hover { color: var(--age-fg); border-color: var(--age-green-border); }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 1px; }
	}

	/* Timeline */
	&__tl {
		position: relative;
		margin: 0;
		padding-left: 26px;

		&::before {
			content: '';
			position: absolute;
			left: 8px;
			top: 6px;
			bottom: 6px;
			width: 2px;
			background: var(--age-border);
		}
	}

	&__tl-item { position: relative; padding: 0 0 15px; }
	&__tl-item:last-child { padding-bottom: 0; }

	&__tl-mark {
		position: absolute;
		left: -26px;
		top: 1px;
		width: 18px;
		height: 18px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		background: var(--age-surface);
		border: 2px solid var(--age-border-strong);
		color: #fff;

		svg { width: 11px; height: 11px; }

		&--ok { border-color: var(--age-green); background: var(--age-green); }
		&--err { border-color: var(--age-err-text); background: var(--age-err-text); }
		&--skip { border-style: dashed; }
		&--empty { border-style: dashed; }
	}

	&__tl-body { min-width: 0; }
	&__tl-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
	&__tl-name { font-weight: 600; font-size: 13.5px; text-transform: capitalize; }
	&__tl-codes { margin-top: 4px; font-size: 12px; color: var(--age-fg-muted); }

	/* Provider */
	&__prov { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
	&__prov-badge {
		font-weight: 700;
		font-size: 13px;
		letter-spacing: 0.03em;
		text-transform: uppercase;
		color: var(--age-fg);
		background: var(--age-surface-2);
		border: 1px solid var(--age-border);
		padding: 4px 10px;
		border-radius: 8px;
	}
	&__prov-route { font-size: 12px; color: var(--age-fg-faint); }

	/* Raw */
	&__raw {
		font-family: var(--age-mono);
		font-size: 11.5px;
		line-height: 1.55;
		background: var(--age-surface-2);
		border: 1px solid var(--age-border);
		border-radius: var(--age-radius-sm);
		padding: 12px 14px;
		overflow: auto;
		max-height: 340px;
		margin: 0;
		white-space: pre;
		color: var(--age-fg);
	}

	&__diag {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		font-size: 12px;
		color: var(--age-warn-text);
		background: var(--age-warn-bg);
		border-radius: 7px;
		padding: 8px 11px;

		.pdm__diag-ic { width: 15px; height: 15px; flex: none; margin-top: 1px; }
		&--neutral { color: var(--age-fg-muted); background: var(--age-surface-2); border: 1px solid var(--age-border); }
	}

	&__note {
		font-size: 12px;
		color: var(--age-fg-muted);
		background: var(--age-surface-2);
		border: 1px solid var(--age-border);
		border-radius: var(--age-radius-sm);
		padding: 9px 12px;
	}
}

/* tab-switch transition (PaymentStep) */
.tab-switch-enter-active,
.tab-switch-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.tab-switch-enter-from { opacity: 0; transform: translateY(4px); }
.tab-switch-leave-to { opacity: 0; transform: translateY(-4px); }

@keyframes pdm-spin { to { transform: rotate(360deg); } }

@media (max-width: 560px) {
	.pdm__grid,
	.pdm__meta { grid-template-columns: 1fr; }
	.pdm__tab { font-size: 12.5px; padding: 8px 8px; }
}

@media (prefers-reduced-motion: reduce) {
	.pdm__spinner { animation-duration: 1.4s; }
	.tab-switch-enter-active,
	.tab-switch-leave-active { transition: none; }
}
</style>
