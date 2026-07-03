<template>
	<div class="recovery-screen">

		<div class="recovery-screen__header">
			<div class="recovery-screen__icon">
				<NcIconSvgWrapper :path="mdiClockOutline" :size="16" />
			</div>
			<div class="recovery-screen__heading">
				<span class="recovery-screen__title">Payment timed out</span>
				<span class="recovery-screen__subtitle">
					We didn't receive confirmation in time. What happened?
				</span>
			</div>
		</div>

		<div
			class="recovery-reasons"
			role="radiogroup"
			aria-label="What happened with your payment"
		>
			<button
				v-for="reason in reasons"
				:key="reason.value"
				class="recovery-reason"
				:class="{ 'recovery-reason--selected': selectedReason === reason.value }"
				role="radio"
				:aria-checked="selectedReason === reason.value"
				@click="selectReason(reason.value)"
			>
				<NcIconSvgWrapper
					class="recovery-reason__icon"
					:path="reason.icon"
					:size="17"
				/>
				<span class="recovery-reason__content">
					<span class="recovery-reason__label">{{ reason.label }}</span>
					<span class="recovery-reason__hint">{{ reason.hint }}</span>
				</span>
				<transition name="check-pop">
					<NcIconSvgWrapper
						v-if="selectedReason === reason.value"
						class="recovery-reason__check"
						:path="mdiCheck"
						:size="16"
					/>
				</transition>
			</button>
		</div>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- CONTEXTUAL NOTE                                        -->
		<!-- Surfaces only for reasons with provider-specific       -->
		<!-- guidance (currently wrong-PIN → M-Pesa release delay). -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<transition name="note-expand">
			<div v-if="activeNote" class="recovery-note">
				<NcIconSvgWrapper
					class="recovery-note__icon"
					:path="mdiInformationOutline"
					:size="13"
				/>
				<span>{{ activeNote }}</span>
			</div>
		</transition>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- ACTIONS                                                -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="recovery-screen__actions">

			<!--
				Primary CTA — grayed until a reason is selected.
				Forces the user to identify what happened rather
				than tapping straight through.
			-->
			<button
				class="recovery-cta"
				:class="{
					'recovery-cta--active': !!selectedReason && !isInvalidating,
					'recovery-cta--loading': isInvalidating,
				}"
				:disabled="!selectedReason || isInvalidating"
				@click="confirmRestart"
			>
				<span v-if="isInvalidating" class="spinner spinner--sm spinner--light" />
				<span v-else>{{ ctaLabel }}</span>
			</button>

			<!--
				Secondary — the user realises the payment may still
				land and chooses to keep waiting. Returns to timeout.
			-->
			<button class="recovery-back" @click="$emit('back')">
				Go back and keep waiting
			</button>

		</div>

		<!-- ═══════════════════════════════════════════════════════ -->
		<!-- SAFETY NOTE — always visible, addresses double-charge  -->
		<!-- anxiety before the user commits to a restart.          -->
		<!-- ═══════════════════════════════════════════════════════ -->
		<div class="recovery-screen__safety">
			<NcIconSvgWrapper :path="mdiLockOutline" :size="11" />
			Your previous attempt will be cancelled before a new one starts.
		</div>

	</div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import {
	mdiClockOutline,
	mdiCheck,
	mdiInformationOutline,
	mdiLockOutline,
	mdiBellOffOutline,
	mdiClose,
	mdiLockAlertOutline,
	mdiSwapHorizontal,
} from '@mdi/js'
import type { RecoveryReason } from '@/payment/usePaymentSession'

interface ReasonOption {
	value: RecoveryReason
	label: string
	hint: string
	icon: string
	/**
	 * Optional guidance shown beneath the list when this reason
	 * is selected. Surfaces provider-specific behaviour the user
	 * should know before retrying.
	 */
	note?: string
}

// ─────────────────────────────────────────────────────────────
// Props / Emits
// ─────────────────────────────────────────────────────────────

defineProps<{
	/**
	 * True while POST /payment/invalidate is in-flight. Drives
	 * the loading state on the confirm button.
	 */
	isInvalidating: boolean
}>()

const emit = defineEmits<{
	/**
	 * User confirmed restart. PaymentStep handles invalidation,
	 * session reset, phone pre-population, and return to idle.
	 */
	(e: 'restart', reason: RecoveryReason): void
	/** User chose to keep waiting — return to timeout state. */
	(e: 'back'): void
}>()

// ─────────────────────────────────────────────────────────────
// State
// ─────────────────────────────────────────────────────────────

const selectedReason = ref<RecoveryReason | null>(null)

// ─────────────────────────────────────────────────────────────
// Reason definitions
// ─────────────────────────────────────────────────────────────

const reasons: ReasonOption[] = [
	{
		value: 'no_prompt',
		label: "I didn't receive the prompt",
		hint: 'The request never appeared on my phone.',
		icon: mdiBellOffOutline,
	},
	{
		value: 'dismissed',
		label: 'I dismissed it by accident',
		hint: 'It appeared but I closed it before approving.',
		icon: mdiClose,
	},
	{
		value: 'wrong_pin',
		label: 'I entered the wrong PIN',
		hint: 'The PIN I typed was incorrect.',
		icon: mdiLockAlertOutline,
		note: 'M-Pesa may take a moment to release after a wrong PIN. If the first retry fails, wait 30 seconds before trying again.',
	},
	{
		value: 'different_number',
		label: 'I want to use a different number',
		hint: 'Pay from another mobile money account.',
		icon: mdiSwapHorizontal,
	},
]

// ─────────────────────────────────────────────────────────────
// Derived
// ─────────────────────────────────────────────────────────────

const activeNote = computed(() => {
	if (!selectedReason.value) return null
	return reasons.find(r => r.value === selectedReason.value)?.note ?? null
})

const ctaLabel = computed(() => {
	if (!selectedReason.value) return 'Select a reason to continue'
	return selectedReason.value === 'different_number'
		? 'Restart and change number'
		: 'Restart payment'
})

// ─────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────

function selectReason(reason: RecoveryReason) {
	selectedReason.value = reason
}

function confirmRestart() {
	if (!selectedReason.value) return
	emit('restart', selectedReason.value)
}
</script>

<style scoped>
/*
	Tokens (--green, --amber, --radius-*, --transition-*) cascade
	from .payment-step, which always wraps this component. No
	redeclaration — single source of truth stays in PaymentStep.
*/

.recovery-screen {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

/* ── Header ─────────────────────────────────────────────── */

.recovery-screen__header {
	display: flex;
	align-items: flex-start;
	gap: 11px;
}

.recovery-screen__icon {
	flex-shrink: 0;
	width: 30px;
	height: 30px;
	border-radius: 50%;
	background: var(--amber-bg);
	border: 0.5px solid var(--amber-border);
	color: var(--amber);
	display: flex;
	align-items: center;
	justify-content: center;
	margin-top: 1px;
}

.recovery-screen__heading {
	display: flex;
	flex-direction: column;
	gap: 3px;
	padding-top: 1px;
}

.recovery-screen__title {
	font-size: 13px;
	font-weight: 600;
	color: #111827;
	line-height: 1.3;
}

.recovery-screen__subtitle {
	font-size: 12px;
	color: #64748b;
	line-height: 1.4;
}

/* ── Reasons ────────────────────────────────────────────── */

.recovery-reasons {
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.recovery-reason {
	display: flex;
	align-items: center;
	gap: 11px;
	width: 100%;
	padding: 10px 11px;
	border-radius: var(--radius-sm);
	border: 1px solid #e5e7eb;
	background: transparent;
	cursor: pointer;
	text-align: left;
	transition:
		border-color var(--transition-snappy),
		background var(--transition-snappy),
		box-shadow var(--transition-snappy);
}

.recovery-reason:hover:not(.recovery-reason--selected) {
	border-color: #d1d5db;
	background: #f9fafb;
}

.recovery-reason--selected {
	border-color: var(--green);
	background: var(--green-dim);
	box-shadow: 0 0 0 1px var(--green-border);
}

.recovery-reason__icon {
	flex-shrink: 0;
	color: #94a3b8;
	transition: color var(--transition-snappy);
}

.recovery-reason--selected .recovery-reason__icon {
	color: var(--green-text);
}

.recovery-reason__content {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.recovery-reason__label {
	font-size: 12.5px;
	font-weight: 500;
	color: #111827;
	line-height: 1.3;
}

.recovery-reason--selected .recovery-reason__label {
	color: var(--green-text);
}

.recovery-reason__hint {
	font-size: 11.5px;
	color: #94a3b8;
	line-height: 1.4;
}

.recovery-reason--selected .recovery-reason__hint {
	color: var(--green-text);
	opacity: 0.7;
}

.recovery-reason__check {
	flex-shrink: 0;
	color: var(--green);
}

/* ── Contextual note ────────────────────────────────────── */

.recovery-note {
	display: flex;
	align-items: flex-start;
	gap: 6px;
	padding: 9px 11px;
	border-radius: var(--radius-sm);
	background: var(--amber-bg);
	border: 0.5px solid var(--amber-border);
	font-size: 11.5px;
	color: var(--amber-text);
	line-height: 1.5;
}

.recovery-note__icon {
	flex-shrink: 0;
	margin-top: 1px;
	color: var(--amber);
}

/* ── Actions ────────────────────────────────────────────── */

.recovery-screen__actions {
	display: flex;
	flex-direction: column;
	gap: 7px;
}

.recovery-cta {
	width: 100%;
	height: 42px;
	border-radius: var(--radius-md);
	border: none;
	font-size: 13.5px;
	font-weight: 600;
	letter-spacing: 0.01em;
	cursor: not-allowed;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	background: #f1f5f9;
	color: #94a3b8;
	transition:
		background var(--transition-snappy),
		color var(--transition-snappy),
		transform var(--transition-snappy),
		box-shadow var(--transition-snappy);
}

.recovery-cta--active {
	background: var(--green);
	color: var(--green-dark);
	cursor: pointer;
}

.recovery-cta--active:hover {
	background: #05e87a;
	transform: translateY(-1px);
	box-shadow: 0 4px 14px rgba(4, 213, 109, 0.35);
}

.recovery-cta--active:active {
	transform: scale(0.98);
	box-shadow: none;
}

.recovery-cta--loading {
	background: var(--green);
	color: var(--green-dark);
	opacity: 0.85;
	cursor: wait;
}

.recovery-back {
	width: 100%;
	height: 34px;
	border: none;
	background: transparent;
	font-size: 12px;
	color: #94a3b8;
	cursor: pointer;
	transition: color var(--transition-snappy);
}

.recovery-back:hover {
	color: #374151;
}

/* ── Safety note ────────────────────────────────────────── */

.recovery-screen__safety {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	font-size: 11px;
	color: #cbd5e1;
	letter-spacing: 0.02em;
}

/* ── Transitions ────────────────────────────────────────── */

.check-pop-enter-active {
	transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.18s ease;
}
.check-pop-leave-active {
	transition: opacity 0.12s ease;
}
.check-pop-enter-from {
	opacity: 0;
	transform: scale(0.4);
}
.check-pop-leave-to {
	opacity: 0;
}

.note-expand-enter-active {
	transition: opacity 0.24s ease, transform 0.24s cubic-bezier(0.34, 1.56, 0.64, 1);
	overflow: hidden;
}
.note-expand-leave-active {
	transition: opacity 0.14s ease;
}
.note-expand-enter-from {
	opacity: 0;
	transform: translateY(-4px);
}
.note-expand-leave-to {
	opacity: 0;
}

/* Spinner — inherited pattern from PaymentStep */
.spinner {
	display: inline-block;
	border-radius: 50%;
	border-style: solid;
	border-color: transparent;
	animation: spin 0.65s linear infinite;
	flex-shrink: 0;
}
.spinner--sm {
	width: 13px;
	height: 13px;
	border-width: 2px;
	border-top-color: #64748b;
}
.spinner--light {
	border-top-color: var(--green-dark) !important;
}
@keyframes spin {
	to { transform: rotate(360deg); }
}
</style>
