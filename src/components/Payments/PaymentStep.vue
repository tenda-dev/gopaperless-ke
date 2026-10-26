<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="payment-step">
		<Toaster rich-colors position="bottom-center" />

		<!-- ───────────────── SUMMARY ───────────────── -->
		<div v-if="isLoadingData" class="pay-summary pay-summary--skeleton">
			<span class="pay-skel" style="width: 150px; height: 13px;"></span>
			<span class="pay-skel" style="width: 72px; height: 22px;"></span>
		</div>
		<div v-else-if="product && displayAmount" class="pay-summary">
			<div class="pay-summary__left">
				<div class="pay-summary__doc">
					<span class="pay-summary__ico">
						<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiFileDocumentOutline" /></svg>
					</span>
					<span class="pay-summary__name">{{ presentation.summary.title }}</span>
				</div>
				<p class="pay-summary__reason">{{ presentation.summary.subtitle }}</p>
				<p v-if="displayAmount.hasFx" class="pay-summary__fx">Charged in your local currency</p>
			</div>
			<div class="pay-summary__right">
				<div class="pay-summary__amount">{{ displayAmount.primary }}</div>
				<div v-if="displayAmount.secondary" class="pay-summary__secondary">≈ {{ displayAmount.secondary }}</div>
				<div class="pay-summary__curr">{{ displayAmount.currency }}</div>
			</div>
		</div>

		<!-- ───────────────── METHOD SWITCH ───────────────── -->
		<div class="pay-method" :class="{ 'is-locked': isMethodLocked, 'is-card': selectedMethod === 'card' }">
			<span class="pay-method__ind" aria-hidden="true"></span>
			<button class="pay-method__tab" :class="{ active: selectedMethod === 'mobile' }"
				:disabled="isMethodLocked" type="button" @click="selectMethod('mobile')">
				<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCellphone" /></svg>
				Mobile money
			</button>
			<button class="pay-method__tab" :class="{ active: selectedMethod === 'card' }"
				:disabled="isMethodLocked" type="button" @click="selectMethod('card')">
				<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCreditCardOutline" /></svg>
				Card
			</button>
		</div>

		<!-- ───────────────── FORM MODE ───────────────── -->
		<div v-if="!isFocusState" class="pay-body">

			<!-- MOBILE -->
			<div v-if="selectedMethod === 'mobile'">
				<label class="pay-label">Mobile money number</label>
				<div class="pay-hero" :class="{
					'is-focused': phoneFocused,
					'is-valid': resolution?.isValid && mnoDetection.state !== 'detecting',
					'is-locked': hasActivePaymentSession || mnoDetection.state === 'detecting',
					'show-provider': !!inlineProvider,
				}">
					<div class="pay-hero__row">
						<transition name="pay-fade">
							<span v-if="inlineProvider" class="pay-hero__logo">
								<img v-if="inlineProvider.logo" :src="inlineProvider.logo"
									:alt="inlineProvider.label" @error="onLogoError(inlineProvider.provider)" />
								<span v-else class="pay-hero__mono" :style="monoStyle(inlineProvider.provider)">
									{{ monoLetter(inlineProvider.label) }}
								</span>
							</span>
						</transition>
						<input ref="phoneInputRef" v-model="phoneInput" class="pay-hero__input"
							type="tel" inputmode="tel" placeholder="+254 712 345 678" autocomplete="off"
							:disabled="hasActivePaymentSession || mnoDetection.state === 'detecting'"
							@focus="phoneFocused = true" @blur="phoneFocused = false" @input="onPhoneInput" />
						<span class="pay-hero__end">
							<span v-if="mnoDetection.state === 'detecting'" class="spinner spinner--xs"></span>
							<span v-else-if="resolution?.isValid" class="pay-hero__check">
								<svg class="icon icon--xs" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCheck" /></svg>
							</span>
						</span>
					</div>
					<div class="pay-hero__foot" :class="{ 'is-hint': !inlineProvider }">
						<template v-if="inlineProvider">
							<span class="pay-hero__id">
								<svg class="icon icon--xs" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCheckCircle" /></svg>
								<span class="pay-hero__idname">{{ inlineProvider.label }}</span>
								<span class="pay-hero__iddot" aria-hidden="true">·</span>
								<span class="pay-hero__idcountry">{{ inlineProvider.flag }} {{ inlineProvider.country }}</span>
							</span>
							<button v-if="canEditProvider && !isDaraja" class="pay-hero__change"
								type="button" @click.stop="openMnoSelector">Change</button>
						</template>
						<template v-else>
							<svg class="icon icon--xs" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiInformationOutline" /></svg>
							<span>{{ heroFootText }}</span>
						</template>
					</div>
				</div>

				<!-- PROVIDER PICKER — only when the backend requires selection -->
				<transition name="pay-expand">
					<div v-if="showPicker" class="pay-picker">
						<p class="pay-picker__lbl">
							<template v-if="mnoDetection.state === 'suggested'">
								We detected <span class="hl">{{ formatMnoLabel(canonicalMno(mnoDetection.mno || '')) }}.</span> Confirm or choose another:
							</template>
							<template v-else>Choose your mobile money provider</template>
						</p>
						<div class="pay-picker__opts" role="group" aria-label="Select mobile provider">
							<button v-for="option in mnoDetection.options" :key="option.provider" class="pay-opt"
								:class="{
									'is-pre': mnoDetection.mno === option.provider && !mnoDetection.selected,
									'is-sel': mnoDetection.selected?.provider === option.provider,
								}"
								:disabled="isProcessing" type="button" @click.stop="selectMnoOption(option)">
								<span class="pay-opt__logo">
									<img v-if="providerLogo(option.provider, option.logo)"
										:src="providerLogo(option.provider, option.logo)!"
										:alt="formatMnoLabel(canonicalMno(option.provider))" @error="onLogoError(option.provider)" />
									<span v-else class="pay-opt__mono" :style="monoStyle(option.provider)">
										{{ monoLetter(formatMnoLabel(canonicalMno(option.provider))) }}
									</span>
								</span>
								<span>{{ formatMnoLabel(canonicalMno(option.provider)) }}</span>
							</button>
						</div>
					</div>
				</transition>
			</div>

			<!-- CARD -->
			<div v-else class="pay-card">
				<div class="pay-card__logos">
					<span class="pay-card__logo">VISA</span>
					<span class="pay-card__logo">MASTERCARD</span>
				</div>
				<div class="pay-card__hint">
					<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiOpenInNew" /></svg>
					<span>You'll continue to our secure payment partner to complete your payment. No card details are stored here.</span>
				</div>
			</div>
		</div>

		<!-- ───────────────── FOCUS MODE (processing / outcomes) ───────────────── -->
		<transition v-else :name="transitionName" mode="out-in">
			<div :key="statusKey" class="pay-focus" :class="focusClass">
				<template v-if="payment.isOffline.value">
					<div class="pay-focus__ring"><svg class="icon pay-focus__big" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiWifiOff" /></svg></div>
					<div class="pay-focus__main">You're offline</div>
					<div class="pay-focus__sub">We'll resume checking your payment when you're back online.</div>
				</template>

				<template v-else-if="payment.state.value === 'processing'">
					<div class="pay-focus__ring"><span class="spinner spinner--lg"></span></div>
					<div v-if="displayAmount" class="pay-focus__amount">{{ displayAmount.primary }}</div>
					<div class="pay-focus__main">{{ paymentMessage }}</div>
					<div class="pay-focus__sub">Waiting for payment confirmation · Please keep this window open.</div>
					<div class="pay-focus__dots" aria-hidden="true">
						<span class="dot on"></span>
						<span class="dot" :class="{ on: processingStage >= 1 }"></span>
						<span class="dot" :class="{ on: processingStage >= 2 }"></span>
					</div>
				</template>

				<template v-else-if="payment.state.value === 'success'">
					<div class="pay-focus__ring"><svg class="icon pay-focus__big" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCheck" /></svg></div>
					<div class="pay-focus__main">Payment confirmed</div>
				</template>

				<template v-else-if="payment.state.value === 'error'">
					<div class="pay-focus__ring"><svg class="icon pay-focus__big" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiAlertCircleOutline" /></svg></div>
					<div class="pay-focus__main">{{ payment.error.value || "Payment couldn't be completed" }}</div>
					<div class="pay-focus__sub">You can try again</div>
				</template>

				<template v-else-if="payment.state.value === 'timeout'">
					<div class="pay-focus__ring"><svg class="icon pay-focus__big" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiClockOutline" /></svg></div>
					<div class="pay-focus__main">Still waiting for payment confirmation</div>
					<div class="pay-focus__sub">Tap Retry payment to check the latest status.</div>
				</template>

				<template v-else-if="payment.state.value === 'cancelled'">
					<div class="pay-focus__ring"><svg class="icon pay-focus__big" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCloseCircleOutline" /></svg></div>
					<div class="pay-focus__main">Payment cancelled</div>
					<div class="pay-focus__sub">You can try again whenever you're ready.</div>
				</template>
			</div>
		</transition>

		<!-- ───────────────── RECOVERY ───────────────── -->
		<transition name="pay-fade">
			<div v-if="payment.showRecoveryAction.value" class="pay-reset">
				<div class="pay-reset__content">
					<div class="pay-reset__t">Having trouble completing payment?</div>
					<div class="pay-reset__d">You can safely restart the payment flow or choose a different payment method.</div>
				</div>
				<button class="pay-reset__b" type="button" @click="handleCancelPaymentSession">Restart Payment</button>
			</div>
		</transition>

		<!-- ───────────────── CTA ───────────────── -->
		<button class="pay-cta" :class="{
			'is-active': canContinue && !isProcessing,
			'is-loading': isProcessing,
			'is-disabled': !canContinue || isProcessing,
			'is-card': selectedMethod === 'card' && canContinue,
		}" :disabled="!canContinue || isProcessing" type="button" @click="handlePay">
			<span v-if="isProcessing" class="spinner spinner--sm spinner--light"></span>
			<svg v-else-if="selectedMethod === 'card' && canContinue" class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiArrowRight" /></svg>
			<span class="pay-cta__label">{{ buttonLabel }}</span>
		</button>

		<!-- ───────────────── FOOTER ───────────────── -->
		<div class="pay-footer">
			<svg class="icon icon--xs" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiLockOutline" /></svg>
			Secure Payment
			<span class="pay-footer__links">
				<a href="https://tendaworld.com/gopaperless/" target="_blank" rel="noopener noreferrer" class="pay-footer__link">Terms</a>
				<a href="https://tendaworld.com/gopaperless/" target="_blank" rel="noopener noreferrer" class="pay-footer__link">Privacy</a>
			</span>
		</div>
	</div>
</template>

<script setup lang="ts">
/**
 * PAYMENT STEP (UX ORCHESTRATOR)
 *
 * Controls the USER FLOW only. Payment state is owned by the backend and
 * the payment composables; this component composes them and wires the
 * template. Execution / display / MNO / phone logic lives in:
 * usePaymentSession, usePaymentDisplay, useMnoDetection, usePaymentPhoneResolution
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Toaster } from 'vue-sonner'
import 'vue-sonner/style.css'
import {
	mdiFileDocumentOutline, mdiCellphone, mdiCreditCardOutline, mdiCheck, mdiCheckCircle,
	mdiInformationOutline, mdiOpenInNew, mdiWifiOff, mdiAlertCircleOutline, mdiClockOutline,
	mdiCloseCircleOutline, mdiArrowRight, mdiLockOutline,
} from '@mdi/js'

import {
	usePayment,
	type MobilePaymentContext,
	type PaymentMethod,
	type HydratedPayment,
	type PaymentPurpose,
	type PaymentPresentation,
} from '@/payment'
import { showError, showInfo } from '@/services/toast'

import { loadState } from '@nextcloud/initial-state'
import { resolvePhone } from '@/utils/phoneResolver'
import type { SupportedRegion } from '@/utils/phoneResolver'
import '@/style/global.scss'
import { usePaymentRecovery } from '@/payment/usePaymentRecovery'
import { usePaymentRouting } from '@/payment/usePaymentRouting'
import type { ProductPricing } from '@/composables/useProductPricing'
import { useUserContextStore } from '@/store/userContext.ts'
import type { Product } from '@/payment/product.ts'
import { usePaymentPhoneResolution } from '@/payment/usePaymentPhoneResolution'
import { useMnoDetection } from '@/payment/useMnoDetection'
import { usePaymentDisplay } from '@/payment/usePaymentDisplay'
import { usePaymentSession } from '@/payment/usePaymentSession'
import { logoFor, canonicalMno } from '@/payment/mnoLogo'

// Props / Emits
const props = defineProps<{
	signUuid?: string
	signRequestId?: number
	sponsorshipWorkflowFileUuid?: string
	paymentPurpose: PaymentPurpose
	quantity: number
	productCode: string
	pricing: ProductPricing
	initialPayment?: HydratedPayment | null
	presentation: PaymentPresentation
}>()

const emit = defineEmits([
	'payment-success',
	'state-change',
	'payment-runtime-invalid',
])

// External state
const payment = usePayment()
const { discardRecovery } = usePaymentRecovery()
const routing = usePaymentRouting()
const userContext = useUserContextStore()

/**
 * Backend may resolve signUuid from signRequestId during initiate().
 * Cache the resolved UUID so the charge call can proceed even when the
 * parent only passed signRequestId. Owned here, injected into the session.
 */
const resolvedSignUuid = ref<string | null>(props.signUuid ?? null)

// UI state
const selectedMethod = ref<PaymentMethod>('mobile')
const defaultPhoneRegion = loadState<SupportedRegion>('libresign', 'default_phone_region', 'KE')
const phoneFocused = ref(false)
const phoneInputRef = ref<HTMLInputElement | null>(null)
const phoneInput = ref('')
const resolution = ref<ReturnType<typeof resolvePhone> | null>(null)
const product = ref<Product | null>(null)
const user = ref<any>(null)
const isLoadingData = ref(true)
const hasEmittedSuccess = ref(false)
const mobilePaymentContext = ref<MobilePaymentContext | null>(null)
const isHydrating = ref(false)
const detectedRegion = ref<string>('KE')

// Derived (payment-owned)
const isDaraja = computed(() => {
	if (payment.provider.value) {
		return payment.provider.value === 'daraja'
	}
	return resolution.value?.provider === 'daraja'
})

const normalisedPhone = computed(() => resolution.value?.e164 ?? '')
const isProcessing = payment.isProcessing

const isMethodLocked = computed(() => isProcessing.value || !!payment.activeReference.value)
const hasActivePaymentSession = computed(() => !!payment.activeReference.value)
const hasChargedPayment = computed(() => payment.alreadyCharged.value)
const canEditProvider = computed(() =>
	!!payment.activeReference.value &&
	!hasChargedPayment.value &&
	payment.state.value === 'idle',
)

const statusKey = computed(() => {
	if (payment.isOffline.value) return 'offline'
	return payment.state.value
})

const transitionName = computed(() => {
	if (payment.state.value === 'success') return 'success-pop'
	return 'status-swap'
})

function selectMethod(m: PaymentMethod) {
	if (isProcessing.value) return
	selectedMethod.value = m
}

// Composables
const { applyPhoneInput } = usePaymentPhoneResolution({
	phoneInput,
	defaultPhoneRegion,
	resolution,
	detectedRegion,
	isHydrating,
	providerLocked: payment.providerLocked,
})

const {
	mnoDetection,
	resolvedRoute,
	selectMnoOption,
	openMnoSelector,
	resetMnoDetectionState,
	formatMnoLabel,
	isValidSelectedProvider,
	applyMnoDetection,
} = useMnoDetection({
	isDaraja,
	resolution,
	fetchMobileOptions: payment.fetchMobileOptions,
	isProcessing,
	canEditProvider,
})

const { displayAmount, paymentDisplayLabel } = usePaymentDisplay({
	product,
	quantity: computed(() => props.quantity),
	mobilePaymentContext,
	paymentPurpose: payment.paymentPurpose,
})

const canContinue = computed(() => {
	if (selectedMethod.value === 'card') return true

	const hasValidPhone = !!resolution.value?.isValid
	if (!hasValidPhone) return false

	if (isDaraja.value) {
		return true
	}

	if (!mnoDetection.reference && !isDaraja.value) {
		return true
	}

	switch (mnoDetection.state) {
		case 'detected':
			return true
		case 'selected':
			return true
		case 'suggested':
			return !!mnoDetection.selected
		case 'requires-selection':
			return !!mnoDetection.selected
		default:
			return false
	}
})

// ───────────────── Presentation helpers (redesign) ─────────────────
// SupportedRegion -> flag + display name. The country itself comes from the
// backend/detection (detectedRegion / resolvedRoute.country), never hardcoded.
const REGION: Record<string, [string, string]> = {
	KE: ['🇰🇪', 'Kenya'], TZ: ['🇹🇿', 'Tanzania'], UG: ['🇺🇬', 'Uganda'],
	RW: ['🇷🇼', 'Rwanda'], MW: ['🇲🇼', 'Malawi'], ZM: ['🇿🇲', 'Zambia'], ZW: ['🇿🇼', 'Zimbabwe'],
}
const regionFlag = (code?: string | null) => REGION[(code ?? '').toUpperCase()]?.[0] ?? ''
const regionName = (code?: string | null) => REGION[(code ?? '').toUpperCase()]?.[1] ?? (code ?? '')

// Track logo URLs that failed to load so we can fall back to a monogram.
const failedLogos = ref<Set<string>>(new Set())
function onLogoError(provider: string) {
	const next = new Set(failedLogos.value)
	next.add(canonicalMno(provider))
	failedLogos.value = next
}
function providerLogo(provider?: string | null, backendLogo?: string | null): string | null {
	const url = logoFor(provider, backendLogo)
	return url && !failedLogos.value.has(canonicalMno(provider)) ? url : null
}
const monoLetter = (label?: string | null) => (label ?? '?').replace(/[^A-Za-z]/g, '').charAt(0).toUpperCase() || '?'
const MONO_BG: Record<string, string> = {
	mpesa: '#1aa64b', vodacom: '#e60000', airtel: '#e4002b', mtn: '#ffcc00', tigo: '#0057b8',
	ecocash: '#1b6cc4', tnm: '#6a1b9a', zamtel: '#00824b', halotel: '#f26b21', africell: '#6d2b8f',
}
function monoStyle(provider?: string | null) {
	const key = canonicalMno(provider)
	return { background: MONO_BG[key] ?? '#64748b', color: key === 'mtn' ? '#1b2430' : '#fff' }
}

// Inline provider shown inside the number field for the detected/selected case.
const inlineProvider = computed(() => {
	if (selectedMethod.value !== 'mobile') return null
	const route = resolvedRoute.value
	if (!route) return null
	// Show the resolved provider inline whenever a route exists (Daraja shortcut,
	// auto-detected, or user-selected) — matching the reference `v-if="resolvedRoute"`.
	// While the backend still wants an explicit choice, defer to the picker instead.
	if ((mnoDetection.state === 'suggested' || mnoDetection.state === 'requires-selection') && !mnoDetection.selected) return null
	const region = detectedRegion.value || route.country || ''
	return {
		provider: route.provider,
		logo: providerLogo(route.provider, route.logo),
		label: formatMnoLabel(canonicalMno(route.provider)),
		flag: regionFlag(region),
		country: regionName(region),
	}
})

const showPicker = computed(() =>
	!isDaraja.value && (
		mnoDetection.state === 'suggested' ||
		mnoDetection.state === 'requires-selection' ||
		mnoDetection.showSelector
	),
)

const heroFootText = computed(() => {
	if (inlineProvider.value) return 'Payment requests will be sent to this number'
	if (mnoDetection.state === 'detecting') return 'Detecting your network…'
	return 'Enter the phone number linked to your mobile money account.'
})

const isFocusState = computed(() =>
	payment.isOffline.value
	|| ['processing', 'success', 'error', 'timeout', 'cancelled'].includes(payment.state.value),
)

const focusClass = computed(() => {
	if (payment.state.value === 'success') return 'pay-focus--ok'
	if (payment.state.value === 'error') return 'pay-focus--err'
	if (payment.isOffline.value || payment.state.value === 'timeout' || payment.state.value === 'cancelled') return 'pay-focus--warn'
	return ''
})

/**
 * Live view of the parent-supplied payment intent. Getters keep reads
 * current (matching the component's original live props.* reads) without
 * introducing a second source of truth.
 */
const sessionContext = {
	get signUuid() { return props.signUuid },
	get signRequestId() { return props.signRequestId },
	get sponsorshipWorkflowFileUuid() { return props.sponsorshipWorkflowFileUuid },
	get productCode() { return props.productCode },
	get quantity() { return props.quantity },
	get purpose() { return props.paymentPurpose },
}

const {
	handlePay,
	applyHydratedPayment,
	cancelSession: handleCancelPaymentSession,
	buttonLabel,
	paymentMessage,
	processingStage,
} = usePaymentSession({
	payment,
	mnoDetection,
	routing,
	applyMnoDetection,
	resetMnoDetectionState,
	phoneInput,
	resolution,
	detectedRegion,
	normalisedPhone,
	mobilePaymentContext,
	selectedMethod,
	isHydrating,
	sessionContext,
	user,
	onRuntimeInvalid: () => emit('payment-runtime-invalid'),
	discardRecovery,
	selectMethod,
	isDaraja,
	resolvedSignUuid,
	isValidSelectedProvider,
	paymentDisplayLabel,
})

function onPhoneInput() {
	if (hasActivePaymentSession.value) {
		return
	}
	resetMnoDetectionState()
}

// Sync payment state -> parent modal
watch(() => payment.state.value, (state) => {
	emit('state-change', state)
	if (state === 'success' && !hasEmittedSuccess.value) {
		hasEmittedSuccess.value = true
		setTimeout(() => {
			emit('payment-success')
		}, 1000)
	}
})

// Phone input watcher (component-owned; delegates to the composable)
watch(phoneInput, (val) => {
	applyPhoneInput(val)
})

onMounted(async () => {
	if (payment.useMockPayments) {
		showInfo('This is a demo environment. No real money is being transacted.')
	}
	try {
		const { uid, emailAddress, phoneNumber } = useUserContextStore()
		user.value = { uid, emailAddress, phoneNumber }
		product.value = props.pricing.product.value

		if (props.initialPayment) {
			applyHydratedPayment(props.initialPayment)
		} else {
			payment.paymentPurpose.value = props.paymentPurpose
		}
	} catch (e) {
		showError('Failed to load payment data')
	} finally {
		isLoadingData.value = false
	}
})

onUnmounted(() => {
	payment.stopPolling()
})
</script>

<style scoped lang="scss">
@use './tokens' as pay;

.payment-step {
	@include pay.pay-tokens;
	display: flex;
	flex-direction: column;
	color: var(--pay-fg);
	font-size: 14px;
	// Self-contained padding: the NcDialog content wrapper resets its own
	// padding to 0, so the step must not rely on the modal for inner spacing.
	padding: 22px;
	box-sizing: border-box;
}

.icon {
	width: 16px;
	height: 16px;
	fill: currentColor;
	flex: none;
}

.icon--sm {
	width: 15px;
	height: 15px;
}

.icon--xs {
	width: 12px;
	height: 12px;
}

/* ── summary ── */
.pay-summary {
	display: flex;
	justify-content: space-between;
	gap: 16px;
	align-items: flex-start;
	padding: 2px 2px 14px;
	border-bottom: 1px solid var(--pay-border);
	margin-bottom: 16px;

	&--skeleton {
		align-items: center;
	}
}

.pay-summary__doc {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.pay-summary__ico {
	width: 20px;
	height: 20px;
	border-radius: 6px;
	background: var(--pay-green-wash);
	color: var(--pay-green-text);
	display: grid;
	place-items: center;
	flex: none;
}

.pay-summary__name {
	font-weight: 600;
	font-size: 14px;
	letter-spacing: -0.01em;
}

.pay-summary__reason {
	margin: 0;
	color: var(--pay-fg-muted);
	font-size: 12px;
	max-width: 220px;
}

.pay-summary__fx {
	margin: 6px 0 0;
	font-size: 10.5px;
	color: var(--pay-info);
	display: inline-block;
}

.pay-summary__right {
	text-align: right;
	flex: none;
}

.pay-summary__amount {
	font-family: var(--pay-font-display);
	font-weight: 700;
	font-size: 24px;
	letter-spacing: -0.025em;
	line-height: 1;
}

.pay-summary__secondary {
	font-size: 11px;
	color: var(--pay-fg-faint);
	margin-top: 3px;
	font-family: var(--pay-font-mono);
}

.pay-summary__curr {
	font-size: 10.5px;
	color: var(--pay-fg-faint);
	letter-spacing: 0.07em;
	margin-top: 4px;
	font-weight: 600;
}

.pay-skel {
	display: inline-block;
	border-radius: 4px;
	background: linear-gradient(90deg, var(--pay-sunken), var(--pay-border), var(--pay-sunken));
	background-size: 200% 100%;
	animation: pay-shimmer 1.3s infinite;
}

@keyframes pay-shimmer {
	to {
		background-position: -200% 0;
	}
}

/* ── method switch ── */
.pay-method {
	position: relative;
	display: grid;
	grid-template-columns: 1fr 1fr;
	background: var(--pay-sunken);
	border-radius: var(--pay-radius-sm);
	padding: 4px;
	margin-bottom: 18px;

	&.is-locked {
		opacity: 0.5;
		pointer-events: none;
	}

	&.is-card .pay-method__ind {
		transform: translateX(100%);
	}
}

.pay-method__ind {
	position: absolute;
	top: 4px;
	bottom: 4px;
	left: 4px;
	width: calc(50% - 4px);
	background: var(--pay-surface);
	border-radius: var(--pay-radius-xs);
	box-shadow: var(--pay-shadow-sm);
	transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.pay-method__tab {
	position: relative;
	z-index: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	font: inherit;
	font-weight: 500;
	font-size: 13px;
	color: var(--pay-fg-muted);
	background: 0;
	border: 0;
	padding: 4px 9px;
	cursor: pointer;
	transition: color 0.18s;

	&.active {
		color: var(--pay-fg);
		font-weight: 600;

		.icon {
			color: var(--pay-green);
		}
	}

	&:disabled {
		cursor: default;
	}
}

/* ── hero phone field ── */
.pay-label {
	display: block;
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: var(--pay-fg-faint);
	margin: 0 0 8px;
}

.pay-hero {
	border: 1.5px solid var(--pay-border-strong);
	border-radius: 14px;
	background: var(--pay-surface);
	transition: border-color 0.18s, box-shadow 0.18s;

	&.is-focused {
		border-color: var(--pay-green);
		box-shadow: 0 0 0 4px var(--pay-green-wash);
	}

	&.is-valid {
		border-color: var(--pay-green-line);
	}

	&.is-locked {
		background: var(--pay-sunken);
		opacity: 0.85;
	}
}

.pay-hero__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 0 14px;
	height: 56px;
}

.pay-hero__logo {
	width: 34px;
	height: 34px;
	border-radius: 9px;
	overflow: hidden;
	background: #fff;
	border: 1px solid var(--pay-border);
	box-shadow: 0 1px 2px rgba(16, 32, 55, 0.08);
	display: inline-grid;
	place-items: center;
	flex: none;
}

.pay-hero__logo img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	padding: 3px;
	box-sizing: border-box;
	display: block;
}

.pay-hero__mono,
.pay-opt__mono {
	display: inline-grid;
	place-items: center;
	width: 100%;
	height: 100%;
	font-family: var(--pay-font-display);
	font-weight: 700;
	font-size: 14px;
}

.pay-hero__input {
	flex: 1;
	min-width: 0;
	border: 0;
	background: transparent;
	font: inherit;
	font-size: 14px;
	color: var(--pay-fg);
	outline: none;
	letter-spacing: 0.01em;
	padding: 0;
}

.pay-hero__input::placeholder {
	color: var(--pay-fg-faint);
}

.pay-hero__input:disabled {
	color: var(--pay-fg-muted);
}

.pay-hero__end {
	flex: none;
	width: 22px;
	display: grid;
	place-items: center;
}

.pay-hero__check {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--pay-green);
	color: #fff;
	display: grid;
	place-items: center;
}

.pay-hero__foot {
	border-top: 1px solid var(--pay-border);
	padding: 9px 14px;
	min-height: 37px;
	font-size: 11.5px;
	color: var(--pay-fg-faint);
	display: flex;
	align-items: center;
	gap: 7px;
}

.pay-hero__foot.is-hint .icon {
	color: var(--pay-fg-faint);
}

.pay-hero__id {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	font-weight: 600;
	color: var(--pay-green-text);
}

.pay-hero__id .icon {
	color: var(--pay-green);
	flex: none;
}

.pay-hero__idname {
	white-space: nowrap;
	flex: none;
}

.pay-hero__iddot {
	color: var(--pay-fg-faint);
	flex: none;
}

.pay-hero__idcountry {
	color: var(--pay-fg-muted);
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pay-hero__change {
	margin-left: auto;
	font: inherit;
	font-size: 11.5px;
	font-weight: 600;
	color: var(--pay-fg-muted);
	background: transparent;
	border: 0;
	padding: 0 0 0 10px;
	cursor: pointer;
	text-decoration: underline;
	text-underline-offset: 2px;
	flex: none;

	&:hover {
		color: var(--pay-fg);
	}
}

.pay-body {
	margin-bottom: 25px;
}

/* ── provider picker (content-hugging chips) ── */
.pay-picker {
	margin-top: 12px;
}

.pay-picker__lbl {
	font-size: 12px;
	color: var(--pay-fg-muted);
	margin: 0 0 9px;
}

.pay-picker__lbl .hl {
	color: var(--pay-fg);
	font-weight: 600;
}

.pay-picker__opts {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pay-opt {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font: inherit;
	font-size: 13px;
	font-weight: 500;
	color: var(--pay-fg);
	background: var(--pay-surface);
	border: 1.5px solid var(--pay-border-strong);
	border-radius: 11px;
	padding: 7px 13px 7px 8px;
	cursor: pointer;
	transition: all 0.15s;

	&:hover {
		border-color: var(--pay-fg-faint);
	}

	&.is-pre {
		border-color: var(--pay-green-line);
		background: var(--pay-green-wash);
	}

	&.is-sel {
		border-color: var(--pay-green);
		box-shadow: 0 0 0 3px var(--pay-green-wash);
	}

	&:disabled {
		opacity: 0.6;
		cursor: default;
	}
}

.pay-opt__logo {
	width: 26px;
	height: 26px;
	border-radius: 7px;
	overflow: hidden;
	background: #fff;
	border: 1px solid var(--pay-border);
	box-shadow: 0 1px 2px rgba(16, 32, 55, 0.06);
	display: inline-grid;
	place-items: center;
	flex: none;
}

.pay-opt__logo img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	padding: 2px;
	box-sizing: border-box;
	display: block;
}

/* ── card ── */
.pay-card__logos {
	display: flex;
	gap: 8px;
	margin: 2px 0 14px;
}

.pay-card__logo {
	font-family: var(--pay-font-mono);
	font-weight: 600;
	font-size: 11px;
	letter-spacing: 0.06em;
	color: var(--pay-fg-muted);
	background: var(--pay-sunken);
	border: 1px solid var(--pay-border);
	border-radius: 7px;
	padding: 6px 11px;
}

.pay-card__hint {
	display: flex;
	gap: 9px;
	align-items: flex-start;
	font-size: 12.5px;
	color: var(--pay-fg-muted);
	line-height: 1.5;
	background: var(--pay-sunken);
	border: 1px solid var(--pay-border);
	border-radius: 12px;
	padding: 13px;

	.icon {
		color: var(--pay-green);
		margin-top: 1px;
	}
}

/* ── focus takeover ── */
.pay-focus {
	text-align: center;
	padding: 16px 8px 8px;
}

.pay-focus__ring {
	width: 64px;
	height: 64px;
	margin: 0 auto 16px;
	border-radius: 50%;
	display: grid;
	place-items: center;
}

.pay-focus__big {
	width: 32px;
	height: 32px;
}

.pay-focus__amount {
	font-family: var(--pay-font-display);
	font-weight: 700;
	font-size: 20px;
	letter-spacing: -0.02em;
	margin-bottom: 6px;
}

.pay-focus__main {
	font-size: 14px;
	font-weight: 600;
	margin-bottom: 4px;
}

.pay-focus__sub {
	font-size: 12px;
	color: var(--pay-fg-muted);
}

.pay-focus__dots {
	display: flex;
	gap: 6px;
	justify-content: center;
	margin-top: 14px;
}

.pay-focus__dots .dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: var(--pay-fg-faint);
	opacity: 0.3;
	transition: opacity 0.3s;

	&.on {
		opacity: 1;
		background: var(--pay-green);
	}
}

.pay-focus--ok .pay-focus__ring {
	background: var(--pay-green-wash);
	color: var(--pay-green-text);
}

.pay-focus--err .pay-focus__ring {
	background: var(--pay-err-bg);
	color: var(--pay-err-text);
}

.pay-focus--warn .pay-focus__ring {
	background: var(--pay-warn-bg);
	color: var(--pay-warn-text);
}

/* ── recovery ── */
.pay-reset {
	margin-top: 14px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	background: var(--pay-sunken);
	border: 1px solid var(--pay-border);
	border-radius: 12px;
	padding: 12px 14px;
}

.pay-reset__t {
	font-weight: 600;
	font-size: 12.5px;
}

.pay-reset__d {
	font-size: 11.5px;
	color: var(--pay-fg-muted);
	margin-top: 2px;
}

.pay-reset__b {
	font: inherit;
	font-size: 12px;
	font-weight: 600;
	color: var(--pay-fg);
	background: var(--pay-surface);
	border: 1px solid var(--pay-border-strong);
	border-radius: 9px;
	padding: 8px 12px;
	cursor: pointer;
	flex: none;
}

/* ── CTA ── */
.pay-cta {
	width: 100%;
	flex: none;
	margin-top: 22px;
	border: 0;
	border-radius: 12px;
	font: inherit;
	font-weight: 600;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	cursor: pointer;
	transition: all 0.18s;

	&.is-active,
	&.is-card {
		background: var(--pay-green);
		color: #fff;
		box-shadow: 0 4px 12px -6px var(--pay-green);

		&:hover {
			background: var(--pay-green-ink);
		}
	}

	&.is-disabled {
		background: var(--pay-sunken);
		color: var(--pay-fg-faint);
		cursor: not-allowed;
		border: 1px solid var(--pay-border);
		box-shadow: none;
	}

	&.is-loading {
		background: var(--pay-sunken);
		color: var(--pay-fg-muted);
		cursor: progress;
		border: 1px solid var(--pay-border);
		box-shadow: none;
	}
}

/* ── footer ── */
.pay-footer {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 16px;
	font-size: 11px;
	color: var(--pay-fg-faint);
}

.pay-footer__links {
	margin-left: auto;
	display: flex;
	gap: 14px;
}

.pay-footer__link {
	color: var(--pay-fg-faint);
	text-decoration: none;

	&:hover {
		color: var(--pay-fg-muted);
	}
}

/* ── spinner ── */
.spinner {
	border: 2px solid currentColor;
	border-top-color: transparent;
	border-radius: 50%;
	animation: pay-spin 0.7s linear infinite;
	flex: none;
	color: var(--pay-green);
}

.spinner--xs {
	width: 13px;
	height: 13px;
}

.spinner--sm {
	width: 15px;
	height: 15px;
}

.spinner--lg {
	width: 62px;
	height: 62px;
	border-width: 3px;
}

.spinner--light {
	color: #fff;
}

@keyframes pay-spin {
	to {
		transform: rotate(360deg);
	}
}

/* â mobile â */
@media (max-width: 480px) {
	.payment-step {
		padding: 18px 16px;
	}

	.pay-summary {
		gap: 12px;
	}

	.pay-summary__reason {
		max-width: none;
	}

	.pay-summary__amount {
		font-size: 21px;
	}

	.pay-hero__row {
		padding: 0 12px;
		gap: 9px;
	}

	.pay-hero__input {
		font-size: 16px;
	}

	.pay-hero__foot {
		padding: 9px 12px;
	}
}

/* ── transitions ── */
.pay-fade-enter-active,
.pay-fade-leave-active {
	transition: opacity 0.2s;
}

.pay-fade-enter-from,
.pay-fade-leave-to {
	opacity: 0;
}

.pay-slide-x-enter-active,
.pay-slide-x-leave-active {
	transition: opacity 0.2s, transform 0.2s;
}

.pay-slide-x-enter-from,
.pay-slide-x-leave-to {
	opacity: 0;
	transform: translateX(-6px);
}

.pay-expand-enter-active,
.pay-expand-leave-active {
	transition: opacity 0.2s, transform 0.2s;
}

.pay-expand-enter-from,
.pay-expand-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

.status-swap-enter-active,
.status-swap-leave-active,
.success-pop-enter-active,
.success-pop-leave-active {
	transition: opacity 0.25s, transform 0.25s;
}

.status-swap-enter-from,
.status-swap-leave-to {
	opacity: 0;
	transform: translateY(6px);
}

.success-pop-enter-from {
	opacity: 0;
	transform: scale(0.96);
}

.success-pop-leave-to {
	opacity: 0;
}
</style>
