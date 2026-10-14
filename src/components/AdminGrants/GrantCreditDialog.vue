<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog v-if="modelValue" :name="dialogTitle" size="normal" @update:open="onOpenChange">
		<!-- STEP: form -->
		<div v-if="step === 'form'" class="grant">
			<div class="grant__rcpt">
				<span class="grant__av">{{ initials }}</span>
				<span class="grant__who">
					<span class="grant__name">{{ user.displayName || user.uid }}</span>
					<span class="grant__mail">{{ user.email || user.uid }} · SIGN_DOCUMENT</span>
				</span>
				<span class="grant__bal">
					<b>{{ currentAvailable }}</b>
					<span>{{ t('libresign', 'available now') }}</span>
				</span>
			</div>

			<label class="grant__q-label">{{ t('libresign', 'How many credits?') }}</label>

			<div class="grant__chips">
				<button v-for="p in PICKS" :key="p" type="button" class="grant__chip" :class="{ on: qty === p }" :disabled="submitting" @click="setQty(p)">+{{ p }}</button>
			</div>

			<div class="grant__qty" :class="{ 'grant__qty--invalid': qtyTouched && !qtyValid }">
				<button type="button" class="grant__qty-btn" :disabled="submitting || qty <= MIN" :aria-label="t('libresign', 'Decrease')" @click="bump(-1)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMinus" /></svg>
				</button>
				<input id="age-qty" v-model="qtyRaw" type="text" inputmode="numeric" class="grant__qty-input" :disabled="submitting" @blur="qtyTouched = true">
				<button type="button" class="grant__qty-btn" :disabled="submitting || qty >= MAX" :aria-label="t('libresign', 'Increase')" @click="bump(1)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiPlus" /></svg>
				</button>
			</div>

			<div class="grant__proj" :class="{ 'grant__proj--muted': !qtyValid }">
				<span class="grant__proj-n">{{ currentAvailable }}</span>
				<svg class="grant__proj-arrow" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiArrowRight" /></svg>
				<span class="grant__proj-to">{{ qtyValid ? currentAvailable + qty : '—' }}</span>
				<span class="grant__proj-lab">{{ t('libresign', 'new balance') }}</span>
			</div>

			<p class="grant__hint" :class="{ 'grant__hint--err': qtyTouched && !qtyValid }">
				{{ qtyTouched && !qtyValid ? qtyError : t('libresign', 'Whole number, {min}–{max}. Grants don’t reconcile payments.', { min: MIN, max: MAX }) }}
			</p>

			<details class="grant__note-wrap">
				<summary>
					<svg class="grant__note-chev" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiChevronDown" /></svg>
					{{ t('libresign', 'Add a reason or reference (optional)') }}
				</summary>
				<div class="grant__note-body">
					<div class="grant__field">
						<label class="grant__label" for="age-reason">{{ t('libresign', 'Reason') }} <span class="grant__optional">({{ t('libresign', 'optional') }})</span></label>
						<textarea id="age-reason" v-model="reason" class="grant__control grant__textarea" :maxlength="REASON_MAX" :disabled="submitting"
							:placeholder="t('libresign', 'e.g. Payment confirmed with provider but credits not allocated.')" />
						<span class="grant__charcount">{{ reason.length }}/{{ REASON_MAX }}</span>
					</div>
					<div class="grant__field">
						<label class="grant__label" for="age-ref">{{ t('libresign', 'Support / incident reference') }} <span class="grant__optional">({{ t('libresign', 'optional') }})</span></label>
						<input id="age-ref" v-model="reference" class="grant__control" type="text" :disabled="submitting" :placeholder="t('libresign', 'e.g. PLANE-1423')">
					</div>
				</div>
			</details>

			<div class="grant__actions">
				<button type="button" class="grant__btn grant__btn--ghost" :disabled="submitting" @click="close">{{ t('libresign', 'Cancel') }}</button>
				<button type="button" class="grant__btn grant__btn--primary" :disabled="!canReview || submitting" @click="goConfirm">{{ t('libresign', 'Review grant') }}</button>
			</div>
		</div>

		<!-- STEP: confirm -->
		<div v-else class="grant">
			<div class="grant__review">
				<div class="grant__rr"><span>{{ t('libresign', 'Recipient') }}</span><span class="grant__rv">{{ user.displayName || user.uid }} · {{ user.uid }}</span></div>
				<div class="grant__rr"><span>{{ t('libresign', 'Product') }}</span><span class="grant__rv">{{ t('libresign', 'Document signing') }}</span></div>
				<div class="grant__rr"><span>{{ t('libresign', 'Credits') }}</span><span class="grant__rv grant__rv--accent">+{{ qty }}</span></div>
				<div class="grant__rr"><span>{{ t('libresign', 'Projected balance') }}</span><span class="grant__rv">{{ currentAvailable }} → {{ currentAvailable + qty }} {{ t('libresign', 'available') }}</span></div>
				<div v-if="reason.trim()" class="grant__rr"><span>{{ t('libresign', 'Reason') }}</span><span class="grant__rv grant__rv--normal">{{ reason.trim() }}</span></div>
				<div v-if="reference.trim()" class="grant__rr"><span>{{ t('libresign', 'Reference') }}</span><span class="grant__rv grant__rv--normal">{{ reference.trim() }}</span></div>
			</div>

			<p class="grant__caution">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiAlertOutline" /></svg>
				{{ t('libresign', 'The projected balance is an estimate from the latest reading. This grant adds credits now and does not reconcile any earlier payment.') }}
			</p>

			<div class="grant__actions">
				<button type="button" class="grant__btn grant__btn--ghost" :disabled="submitting" @click="step = 'form'">{{ t('libresign', 'Back') }}</button>
				<button type="button" class="grant__btn grant__btn--primary" :disabled="submitting" @click="submit">
					<span v-if="submitting" class="grant__spinner" aria-hidden="true" />
					<svg v-else viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCheck" /></svg>
					{{ submitting ? t('libresign', 'Granting…') : t('libresign', 'Grant {n} credits', { n: qty }) }}
				</button>
			</div>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiAlertOutline, mdiArrowRight, mdiCheck, mdiChevronDown, mdiMinus, mdiPlus } from '@mdi/js'

import NcDialog from '@nextcloud/vue/components/NcDialog'

import { notifySuccess, notifyError } from '@/services/toast'
import { useAdminEntitlements, hasDefiniteResponse, ocsError, type AdminUser, type GrantResult } from './useAdminEntitlements'

const MIN = 1
const MAX = 100
const REASON_MAX = 280
const PICKS = [5, 10, 20, 50]

const props = defineProps<{
	modelValue: boolean
	user: AdminUser
	currentAvailable: number
}>()

const emit = defineEmits<{
	(e: 'update:modelValue', value: boolean): void
	(e: 'granted', result: GrantResult): void
	(e: 'failed'): void
}>()

const { grant } = useAdminEntitlements()

const step = ref<'form' | 'confirm'>('form')
const qtyRaw = ref('5')
const qtyTouched = ref(false)
const reason = ref('')
const reference = ref('')
const submitting = ref(false)

const dialogTitle = computed(() => step.value === 'form'
	? t('libresign', 'Grant signing credits')
	: t('libresign', 'Confirm grant'))

const initials = computed(() => {
	const base = (props.user.displayName || props.user.uid || '?').trim()
	return base.charAt(0).toUpperCase() || '?'
})

const qty = computed(() => {
	const raw = qtyRaw.value.trim()
	return /^\d+$/.test(raw) ? parseInt(raw, 10) : NaN
})
const qtyValid = computed(() => /^\d+$/.test(qtyRaw.value.trim()) && qty.value >= MIN && qty.value <= MAX)
const qtyError = computed(() => {
	if (!/^\d+$/.test(qtyRaw.value.trim())) {
		return t('libresign', 'Enter a whole number.')
	}
	return t('libresign', 'Must be between {min} and {max}.', { min: MIN, max: MAX })
})
// Reason is optional now — only a valid quantity is required to review.
const canReview = computed(() => qtyValid.value)

watch(() => props.modelValue, (open) => {
	if (open) {
		step.value = 'form'
		qtyRaw.value = '5'
		qtyTouched.value = false
		reason.value = ''
		reference.value = ''
		submitting.value = false
	}
})

function setQty(v: number) {
	qtyRaw.value = String(Math.min(MAX, Math.max(MIN, v)))
	qtyTouched.value = true
}
function bump(delta: number) {
	const next = (Number.isNaN(qty.value) ? 0 : qty.value) + delta
	setQty(next)
}

function goConfirm() {
	qtyTouched.value = true
	if (canReview.value) {
		step.value = 'confirm'
	}
}

async function submit() {
	if (submitting.value || !canReview.value) {
		return
	}
	submitting.value = true
	try {
		const result = await grant({
			uid: props.user.uid,
			uses: qty.value,
			reason: reason.value.trim() || undefined,
			reference: reference.value.trim() || undefined,
		})
		notifySuccess({ message: t('libresign', '{n} credits granted to {name}', { n: qty.value, name: props.user.displayName || props.user.uid }) })
		emit('granted', result)
		close()
	} catch (err) {
		submitting.value = false
		if (hasDefiniteResponse(err)) {
			notifyError({ message: ocsError(err, t('libresign', 'The grant could not be completed. No credits were granted.')), important: true })
		} else {
			notifyError({ message: t('libresign', 'The request did not complete cleanly. Re-checking the account’s current balance…'), important: true })
			emit('failed')
			close()
		}
	}
}

function close() { emit('update:modelValue', false) }
function onOpenChange(open: boolean) { if (!open) { close() } }
</script>

<style scoped lang="scss">
@use './tokens' as agtokens;

.grant {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	gap: 15px;
	padding: 22px;

	svg { fill: currentColor; display: block; }

	/* recipient */
	&__rcpt {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 12px;
		border: 1px solid var(--age-border);
		background: var(--age-surface-2);
		border-radius: var(--age-radius-sm);
	}
	&__av {
		flex: none;
		width: 30px;
		height: 30px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		background: var(--age-fg);
		color: var(--age-surface);
		font-weight: 700;
		font-size: 13px;
	}
	&__who { min-width: 0; display: flex; flex-direction: column; }
	&__name { font-weight: 600; font-size: 13.5px; }
	&__mail { font-size: 11.5px; color: var(--age-fg-faint); word-break: break-word; }
	&__bal { margin-inline-start: auto; text-align: end; flex: none; }
	&__bal b { font-size: 16px; font-variant-numeric: tabular-nums; }
	&__bal span { display: block; font-size: 10.5px; color: var(--age-fg-faint); }

	&__q-label {
		text-align: center;
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--age-fg-faint);
		font-weight: 600;
		margin-top: 2px;
	}

	/* quick-pick chips */
	&__chips { display: flex; justify-content: center; gap: 7px; flex-wrap: wrap; }
	&__chip {
		border: 1px solid var(--age-border);
		background: var(--age-surface);
		color: var(--age-fg-muted);
		font: inherit;
		font-size: 13px;
		font-weight: 600;
		padding: 6px 15px;
		border-radius: 999px;
		cursor: pointer;
		font-variant-numeric: tabular-nums;

		&:hover:not(:disabled) { color: var(--age-fg); }
		&:disabled { opacity: 0.5; cursor: not-allowed; }
		&.on { background: var(--age-green-dim); color: var(--age-green-text); border-color: var(--age-green-border); }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 2px; }
	}

	/* stepper */
	&__qty {
		display: flex;
		align-items: stretch;
		align-self: center;
		border: 1px solid var(--age-border-strong);
		border-radius: 12px;
		overflow: hidden;
		background: var(--age-surface);

		&--invalid { border-color: var(--age-danger); }
	}
	&__qty-btn {
		display: inline-grid;
		place-items: center;
		width: 50px;
		border: none;
		background: var(--age-surface-2);
		color: var(--age-fg);
		cursor: pointer;

		svg { width: 20px; height: 20px; }
		&:hover:not(:disabled) { background: var(--age-green); color: var(--age-green-ink, #06351f); }
		&:disabled { opacity: 0.4; cursor: not-allowed; }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: -2px; }
	}
	&__qty-input {
		width: 90px;
		height: 50px;
		border: none;
		border-inline: 1px solid var(--age-border);
		text-align: center;
		font-size: 23px;
		font-weight: 600;
		background: var(--age-surface);
		color: var(--age-fg);
		font-variant-numeric: tabular-nums;

		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: -2px; }
	}

	/* projection */
	&__proj {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		padding: 9px 13px;
		border-radius: var(--age-radius-sm);
		background: var(--age-green-dim);

		&--muted { background: var(--age-surface-2); }
	}
	&__proj-n { font-size: 15px; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--age-fg-muted); }
	&__proj-arrow { width: 16px; height: 16px; color: var(--age-green-text); }
	&__proj--muted &__proj-arrow { color: var(--age-fg-faint); }
	&__proj-to { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--age-green-text); }
	&__proj--muted &__proj-to { color: var(--age-fg-faint); }
	&__proj-lab { font-size: 11.5px; color: var(--age-fg-muted); }

	&__hint {
		margin: 0;
		text-align: center;
		font-size: 12px;
		color: var(--age-fg-faint);
		&--err { color: var(--age-danger); }
	}

	/* optional disclosure */
	&__note-wrap {
		border: 1px solid var(--age-border);
		border-radius: var(--age-radius-sm);
		overflow: hidden;

		> summary {
			list-style: none;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 7px;
			padding: 10px 12px;
			font-size: 12.5px;
			font-weight: 600;
			color: var(--age-fg-muted);
			background: var(--age-surface-2);

			&::-webkit-details-marker { display: none; }
			&:hover { color: var(--age-fg); }
		}
		&[open] > summary { border-bottom: 1px solid var(--age-border); }
	}
	&__note-chev { width: 14px; height: 14px; fill: var(--age-fg-faint); transition: transform 0.15s ease; }
	&__note-wrap[open] &__note-chev { transform: rotate(180deg); }
	&__note-body { padding: 13px 12px; display: flex; flex-direction: column; gap: 14px; }

	&__field { display: flex; flex-direction: column; gap: 6px; }
	&__label { font-size: 12.5px; font-weight: 600; color: var(--age-fg-muted); }
	&__optional { font-weight: 500; color: var(--age-fg-faint); }

	&__control {
		width: 100%;
		font-size: 14px;
		padding: 10px 12px;
		border-radius: var(--age-radius-sm);
		border: 1px solid var(--age-border-strong);
		background: var(--age-surface);
		color: var(--age-fg);
		font-family: inherit;

		&::placeholder { color: var(--age-fg-faint); }
		&:focus-visible { outline: none; border-color: var(--age-green-border); box-shadow: 0 0 0 3px var(--age-green-dim); }
		&:disabled { opacity: 0.6; }
	}
	&__textarea { resize: vertical; min-height: 68px; line-height: 1.45; }
	&__charcount { font-size: 11px; color: var(--age-fg-faint); text-align: end; }

	/* actions + custom buttons */
	&__actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }
	&__btn {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		font: inherit;
		font-size: 13px;
		font-weight: 600;
		padding: 9px 16px;
		border-radius: 9px;
		cursor: pointer;
		border: 1px solid transparent;

		svg { width: 17px; height: 17px; }
		&:disabled { opacity: 0.55; cursor: not-allowed; }
		&:focus-visible { outline: 2px solid var(--age-green); outline-offset: 2px; }

		&--ghost {
			background: transparent;
			border-color: var(--age-border);
			color: var(--age-fg-muted);
			&:hover:not(:disabled) { color: var(--age-fg); border-color: var(--age-border-strong); }
		}
		&--primary {
			background: var(--age-green);
			color: var(--age-green-ink, #06351f);
			&:hover:not(:disabled) { filter: brightness(1.05); }
		}
	}
	&__spinner {
		width: 15px;
		height: 15px;
		border-radius: 50%;
		border: 2px solid rgba(6, 53, 31, 0.3);
		border-top-color: currentColor;
		animation: grant-spin 0.7s linear infinite;
	}

	/* confirm review */
	&__review {
		display: flex;
		flex-direction: column;
		gap: 1px;
		background: var(--age-border);
		border: 1px solid var(--age-border);
		border-radius: var(--age-radius-sm);
		overflow: hidden;
	}
	&__rr {
		display: flex;
		justify-content: space-between;
		gap: 16px;
		padding: 11px 14px;
		background: var(--age-surface);
		font-size: 13.5px;

		> span:first-child { color: var(--age-fg-muted); }
	}
	&__rv {
		font-weight: 600;
		text-align: end;
		min-width: 0;
		word-break: break-word;
		color: var(--age-fg);

		&--accent { color: var(--age-green-text); }
		&--normal { font-weight: 500; }
	}

	&__caution {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin: 0;
		font-size: 12.5px;
		color: var(--age-warn-text);
		background: var(--age-warn-bg);
		padding: 10px 12px;
		border-radius: var(--age-radius-sm);

		svg { width: 15px; height: 15px; flex: none; margin-top: 1px; }
	}
}

@keyframes grant-spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
	.grant__note-chev { transition: none; }
	.grant__spinner { animation-duration: 1.4s; }
}
</style>
