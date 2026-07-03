<template>
	<div class="resume-screen">

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- HEADER — pulsing refresh halo signals "in progress"    -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="resume-screen__header">
			<div class="resume-screen__badge">
				<span class="resume-screen__halo" />
				<span class="resume-screen__badge-inner">
					<NcIconSvgWrapper :path="mdiRefresh" :size="22" />
				</span>
			</div>
			<span class="resume-screen__title">You have a payment in progress</span>
			<span class="resume-screen__subtitle">
				We saved your last session so you can pick up where you left off.
			</span>
		</div>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- SESSION SUMMARY — all derived from the payment prop    -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="resume-summary">
			<div class="resume-summary__row">
				<div class="resume-summary__product">
					<NcIconSvgWrapper
						class="resume-summary__product-icon"
						:path="mdiFileDocumentOutline"
						:size="14"
					/>
					<span class="resume-summary__product-label">{{ productLabel }}</span>
				</div>
				<span v-if="amountLabel" class="resume-summary__amount">
					{{ amountLabel }}
				</span>
			</div>

			<div class="resume-summary__meta">
				<span class="resume-summary__status">
					<span class="resume-summary__status-dot" />
					Awaiting confirmation
				</span>

				<span v-if="methodLabel" class="resume-summary__method">
					{{ methodLabel }}
				</span>
			</div>

			<p v-if="parsedUpdatedAt" class="resume-summary__age">
				Last active
				<NcDateTime
					:timestamp="parsedUpdatedAt"
					relative-time="short"
					ignore-seconds
				/>
			</p>
		</div>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- ACTIONS — resume (primary) / start new (secondary)     -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="resume-actions">

			<button
				class="resume-cta"
				:disabled="isInvalidating"
				@click="onResume"
			>
				<NcIconSvgWrapper :path="mdiPlayCircleOutline" :size="15" />
				Resume payment
			</button>
			<span class="resume-hint">Checks your existing payment</span>

			<button
				class="resume-secondary"
				:class="{ 'resume-secondary--loading': isInvalidating }"
				:disabled="isInvalidating"
				@click="onStartNew"
			>
				<span v-if="isInvalidating" class="spinner spinner--sm" />
				<span v-else>Start a new payment</span>
			</button>
			<span class="resume-hint">Begins a fresh request</span>

		</div>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- SAFETY LINE — carries the double-charge trust for both -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="resume-screen__safety">
			<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="12" />
			Either way, you're never charged twice.
		</div>

	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import {
	mdiRefresh,
	mdiFileDocumentOutline,
	mdiPlayCircleOutline,
	mdiShieldCheckOutline,
} from '@mdi/js'
import { paymentDriver } from '@/payment/usePayment'
import type { HydratedPayment, PaymentPurpose } from '@/payment'

// ─────────────────────────────────────────────────────────────
// Props / Emits
// ─────────────────────────────────────────────────────────────

const props = defineProps<{
	/**
	 * The persisted pending session to resume. All display content
	 * (product label, amount, method, last-active) is derived from
	 * this internally — the parent only passes the payment.
	 */
	payment: HydratedPayment
}>()

const emit = defineEmits<{
	/** Reconnect to the live session — does NOT invalidate. */
	(e: 'resume'): void
	/** Old reference invalidated, ready to start fresh. */
	(e: 'start-new'): void
}>()

// ─────────────────────────────────────────────────────────────
// State
// ─────────────────────────────────────────────────────────────

/**
 * True while the start-new invalidation call is in-flight.
 * Loader shows on the secondary button only.
 */
const isInvalidating = ref(false)

// ─────────────────────────────────────────────────────────────
// Derived display content
// ─────────────────────────────────────────────────────────────

/**
 * Purpose-driven product label. Generalises across payment
 * purposes without leaking purchase-specific detail (quantity).
 */
const PURPOSE_LABELS: Record<PaymentPurpose, string> = {
	credit_purchase: 'Credit purchase',
	sign_request: 'Signature payment',
}

const productLabel = computed(() => {
	if (props.payment.paymentPurpose) {
		return PURPOSE_LABELS[props.payment.paymentPurpose]
	}
	return 'Payment'
})

/**
 * Formatted amount from the hydrated session. Falls back to null
 * if the backend didn't supply display fields (older sessions).
 */
const amountLabel = computed(() => {
	const { displayAmountFormatted, displayCurrency } = props.payment
	if (!displayAmountFormatted || !displayCurrency) return null
	return `${displayCurrency} ${displayAmountFormatted}`
})

/**
 * Method + number. Provider name resolves to a friendly label;
 * the raw phone is shown as-is for now (masking util will be a
 * separate follow-up ticket).
 */
const methodLabel = computed(() => {
	const { method, mno, provider, phoneNumber } = props.payment

	if (method === 'card') return 'Card'

	const network =
		mno ??
		(provider === 'daraja' ? 'M-Pesa' : null)

	if (network && phoneNumber) return `${network} · ${phoneNumber}`
	if (network) return network
	return phoneNumber ?? null
})

/**
 * Parse updatedAt into a timestamp NcDateTime can render.
 * Guards against a missing/invalid value.
 */
const parsedUpdatedAt = computed(() => {
	const raw = props.payment.updatedAt
	if (!raw) return null

	const ms = new Date(raw).getTime()
	return Number.isNaN(ms) ? null : ms
})

// ─────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────

/**
 * Resume — reconnect to the existing session and keep watching it.
 *
 * MUST NOT invalidate: the reference is the live payment we're
 * resuming. Invalidating here would kill the very thing we want
 * to reconnect to.
 */
function onResume() {
	if (isInvalidating.value) return
	emit('resume')
}

/**
 * Start new — invalidate the existing reference, then emit so the
 * parent can begin a fresh session.
 *
 * Awaited (unlike recovery's fire-and-forget): the user is
 * deliberately abandoning this reference, so we close it before
 * the new one is created and the loader gives that latency a home.
 * On failure we still emit — startPayment's stale-pending detection
 * expires the orphaned row on the next initiation, so correctness holds.
 */
async function onStartNew() {
	if (isInvalidating.value) return

	isInvalidating.value = true

	try {
		await paymentDriver.invalidatePayment(props.payment.reference)
	} catch (err) {
		console.warn('[PaymentResume] invalidation failed, starting fresh anyway', err)
	} finally {
		isInvalidating.value = false
		emit('start-new')
	}
}
</script>

<style scoped>

.resume-screen {
	--green: #04d56d;
	--green-dim: rgba(4, 213, 109, 0.14);
	--green-border: rgba(4, 213, 109, 0.32);
	--green-text: #0a6636;
	--green-dark: #073d1f;

	--amber: #f59e0b;
	--amber-bg: #fffbeb;
	--amber-border: #fde68a;
	--amber-text: #92400e;

	--red-bg: #fef2f2;
	--red-border: #fecaca;
	--red-text: #991b1b;

	--blue-bg: #eff6ff;
	--blue-border: #bfdbfe;
	--blue-text: #1e40af;

	--radius-sm: 6px;
	--radius-md: 10px;
	--radius-lg: 14px;

	--transition-snappy: 180ms cubic-bezier(0.4, 0, 0.2, 1);
	--transition-smooth: 260ms cubic-bezier(0.4, 0, 0.2, 1);
	--transition-bounce: 320ms cubic-bezier(0.34, 1.56, 0.64, 1);

	display: flex;
	flex-direction: column;
}

.resume-screen__header {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 4px 0 16px;
}

.resume-screen__badge {
	position: relative;
	width: 50px;
	height: 50px;
	margin-bottom: 12px;
}

.resume-screen__halo {
	position: absolute;
	inset: 0;
	border-radius: 50%;
	background: var(--green-dim);
	animation: resume-pulse 2s ease-in-out infinite;
}

.resume-screen__badge-inner {
	position: absolute;
	inset: 0;
	border-radius: 50%;
	background: var(--green-dim);
	color: var(--green-text);
	display: flex;
	align-items: center;
	justify-content: center;
}

.resume-screen__title {
	font-size: 14.5px;
	font-weight: 600;
	color: #111827;
	line-height: 1.35;
}

.resume-screen__subtitle {
	font-size: 12px;
	color: #64748b;
	line-height: 1.5;
	margin-top: 4px;
	max-width: 280px;
}

.resume-summary {
	background: #f8fafc;
	border: 0.5px solid #e2e8f0;
	border-radius: var(--radius-md);
	padding: 12px 13px;
	margin-bottom: 16px;
}

.resume-summary__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 9px;
}

.resume-summary__product {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.resume-summary__product-icon {
	flex-shrink: 0;
	color: #94a3b8;
}

.resume-summary__product-label {
	font-size: 12px;
	font-weight: 500;
	color: #111827;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.resume-summary__amount {
	font-size: 13.5px;
	font-weight: 600;
	color: #111827;
	flex-shrink: 0;
}

.resume-summary__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-top: 9px;
	border-top: 0.5px solid #e5e7eb;
}

.resume-summary__status {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 8px;
	border-radius: 20px;
	background: var(--amber-bg);
	border: 0.5px solid var(--amber-border);
	font-size: 10px;
	font-weight: 500;
	color: var(--amber-text);
	white-space: nowrap;
}

.resume-summary__status-dot {
	width: 5px;
	height: 5px;
	border-radius: 50%;
	background: var(--amber);
	animation: resume-blink 1.4s ease-in-out infinite;
}

.resume-summary__method {
	font-size: 10.5px;
	color: #94a3b8;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.resume-summary__age {
	margin: 8px 0 0;
	font-size: 10.5px;
	color: #cbd5e1;
	letter-spacing: 0.01em;
}

.resume-actions {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.resume-cta {
	width: 100%;
	height: 42px;
	border-radius: var(--radius-md);
	border: none;
	font-size: 13px;
	font-weight: 600;
	letter-spacing: 0.01em;
	background: var(--green);
	color: var(--green-dark);
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	transition:
		background var(--transition-snappy),
		transform var(--transition-snappy),
		box-shadow var(--transition-snappy);
}

.resume-cta:hover:not(:disabled) {
	background: #05e87a;
	transform: translateY(-1px);
	box-shadow: 0 4px 14px rgba(4, 213, 109, 0.35);
}

.resume-cta:active:not(:disabled) {
	transform: scale(0.98);
	box-shadow: none;
}

.resume-cta:disabled {
	opacity: 0.55;
	cursor: not-allowed;
}

.resume-secondary {
	width: 100%;
	height: 38px;
	border-radius: var(--radius-md);
	background: transparent;
	border: 0.5px solid #cbd5e1;
	font-size: 12px;
	font-weight: 500;
	color: #64748b;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	transition:
		background var(--transition-snappy),
		border-color var(--transition-snappy),
		color var(--transition-snappy);
}

.resume-secondary:hover:not(:disabled) {
	background: #f9fafb;
	border-color: #94a3b8;
	color: #374151;
}

.resume-secondary--loading,
.resume-secondary:disabled {
	cursor: wait;
}

.resume-hint {
	font-size: 10.5px;
	color: #94a3b8;
	text-align: center;
	margin-bottom: 8px;
}

.resume-hint:last-of-type {
	margin-bottom: 0;
}

.resume-screen__safety {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	font-size: 10.5px;
	color: #94a3b8;
	letter-spacing: 0.02em;
	margin-top: 14px;
	padding-top: 12px;
	border-top: 0.5px solid #f1f5f9;
}

.resume-screen__safety :deep(svg) {
	color: var(--green);
}

.spinner {
	display: inline-block;
	border-radius: 50%;
	border-style: solid;
	border-color: transparent;
	border-top-color: #64748b;
	animation: spin 0.65s linear infinite;
	flex-shrink: 0;
}

.spinner--sm {
	width: 13px;
	height: 13px;
	border-width: 2px;
}

@keyframes resume-pulse {
	0%, 100% { transform: scale(1); opacity: 0.6; }
	50%      { transform: scale(1.25); opacity: 0; }
}

@keyframes resume-blink {
	0%, 100% { opacity: 1; }
	50%      { opacity: 0.3; }
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

@media (max-width: 380px) {
	.resume-summary__row {
		flex-wrap: wrap;
		gap: 4px;
	}

	.resume-summary__meta {
		flex-wrap: wrap;
	}
}
</style>
