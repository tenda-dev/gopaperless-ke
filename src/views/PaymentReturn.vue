<template>
	<div class="payment-return">

		<Transition name="fade-slide" mode="out-in">

			<div :key="status" class="payment-card" :class="status">

				<div class="card-accent" />

				<!-- Icon -->
				<div class="icon-wrap" :class="iconState" :aria-label="iconLabel">
					<div v-if="status === 'loading'" class="spinner" aria-hidden="true" />
					<NcIconSvgWrapper :path="iconPath" :size="26" class="icon" aria-hidden="true" />
				</div>

				<!-- Badge -->
				<div class="badge" :class="badgeVariant" role="status">
					<NcIconSvgWrapper :path="badgeIconPath" :size="13" class="badge-icon" aria-hidden="true" />
					{{ badgeText }}
				</div>

				<!-- Copy -->
				<div class="copy">
					<h2 class="heading">{{ heading }}</h2>
					<p class="sub">{{ subtext }}</p>
				</div>

				<!-- Detail rows -->
				<div v-if="details.length" class="details">
					<div
						v-for="(detail, i) in details"
						:key="i"
						class="detail-row"
					>
						<NcIconSvgWrapper :path="detail.iconPath" :size="14" class="detail-icon" aria-hidden="true" />
						<span class="detail-label">{{ detail.label }}</span>
						<span class="detail-value" :class="detail.valueClass">{{ detail.value }}</span>
					</div>
				</div>

				<!-- Progress (loading only) -->
				<div v-if="status === 'loading'" class="progress-area" aria-hidden="true">
					<div class="progress-track">
						<div
							class="progress-fill"
							:style="{ width: progressWidth }"
						/>
					</div>
					<div class="step-row">
						<div
							v-for="(dot, i) in dots"
							:key="i"
							class="dot"
							:class="dot.state"
						/>
					</div>
					<p class="step-label">{{ stepLabel }}</p>
				</div>

				<!-- Actions (timeout / error) -->
				<div v-if="actions.length" class="actions">
					<button
						v-for="(action, i) in actions"
						:key="i"
						class="btn"
						:class="action.variant"
						@click="action.handler"
					>
						<NcIconSvgWrapper :path="action.iconPath" :size="15" class="btn-icon" aria-hidden="true" />
						{{ action.label }}
					</button>
				</div>

			</div>

		</Transition>

	</div>
</template>

<script setup lang="ts">
import {
	ref,
	computed,
	onMounted,
	onBeforeUnmount,
} from 'vue'
import { useRouter } from 'vue-router'

import {
	mdiShieldCheckOutline,
	mdiClockOutline,
	mdiCloseCircleOutline,
	mdiRefresh,
	mdiArrowLeft,
	mdiInformationOutline,
	mdiShieldOffOutline,
	mdiAlertCircleOutline,
} from '@mdi/js'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { getPaymentFromUrl, clearPaymentParamsFromUrl } from '@/payment/helpers'
import { notifySuccess, notifyError, notifyInfo } from '@/services/toast'
import { usePaymentReturnVerification } from '@/payment/usePaymentReturnVerification'

type Status = 'loading' | 'timeout' | 'error'

type DotState = 'pending' | 'active' | 'done'

interface Detail {
	iconPath: string
	label: string
	value: string
	valueClass?: string
}

interface Action {
	label: string
	iconPath: string
	variant: 'primary' | 'ghost'
	handler: () => void
}

const router = useRouter()

const payment = getPaymentFromUrl()

const paymentContext = {
	transactionToken: payment.transactionToken!,
	returnTo: payment.returnTo!,
}

const status = ref<Status>('loading')

// Loading progress
const elapsedSec = ref(0)
const stepIndex  = ref(0)

let progressInterval: ReturnType<typeof setInterval> | null = null

const STEPS = [
	'Checking local state…',
	'Contacting payment provider…',
	'Observing backend…',
]

const STEP_THRESHOLDS = [0, 5, 20] // seconds

function startProgress() {
	elapsedSec.value = 0
	stepIndex.value  = 0
	progressInterval = setInterval(() => {
		elapsedSec.value++
		for (let i = STEP_THRESHOLDS.length - 1; i >= 0; i--) {
			if (elapsedSec.value >= STEP_THRESHOLDS[i]) {
				stepIndex.value = i
				break
			}
		}
	}, 1000)
}

function stopProgress() {
	if (progressInterval) {
		clearInterval(progressInterval)
		progressInterval = null
	}
}

const { start, stop, restart } = usePaymentReturnVerification({
	providerReference: paymentContext.transactionToken,
	onSuccess:         handleSuccess,
	onFailure:         handleFailure,
	onCancelled:       handleCancelled,
	onTimeout:         handleTimeout,
})

const iconState = computed(() => status.value)

const iconPath = computed(() => {
	switch (status.value) {
		case 'loading': return mdiShieldCheckOutline
		case 'timeout': return mdiClockOutline
		case 'error':   return mdiCloseCircleOutline
	}
})

const iconLabel = computed(() => {
	switch (status.value) {
		case 'loading': return 'Payment being verified'
		case 'timeout': return 'Verification timed out'
		case 'error':   return 'Payment failed'
	}
})

const badgeVariant = computed(() => {
	switch (status.value) {
		case 'loading': return 'info'
		case 'timeout': return 'warning'
		case 'error':   return 'danger'
	}
})

const badgeIconPath = computed(() => {
	switch (status.value) {
		case 'loading': return mdiShieldCheckOutline
		case 'timeout': return mdiClockOutline
		case 'error':   return mdiAlertCircleOutline
	}
})

const badgeText = computed(() => {
	switch (status.value) {
		case 'loading': return 'Verifying'
		case 'timeout': return 'Taking longer than expected'
		case 'error':   return 'Payment failed'
	}
})

const heading = computed(() => {
	switch (status.value) {
		case 'loading': return 'Confirming your payment'
		case 'timeout': return 'Still waiting for confirmation'
		case 'error':   return "Payment wasn't completed"
	}
})

const subtext = computed(() => {
	switch (status.value) {
		case 'loading':
			return 'Checking with the payment provider. This usually takes a few seconds.'
		case 'timeout':
			return "The payment provider hasn't responded yet. Your payment may still complete."
		case 'error':
			return 'The transaction could not be processed. No amount has been charged.'
	}
})

const details = computed<Detail[]>(() => {
	switch (status.value) {
		case 'timeout':
			return [{
				iconPath:   mdiInformationOutline,
				label:      'This is not a failure',
				value:      'Retry to check again',
				valueClass: 'muted',
			}]
		case 'error':
			return [{
				iconPath:   mdiShieldOffOutline,
				label:      'Reason',
				value:      'Declined by provider',
				valueClass: 'danger',
			}]
		default:
			return []
	}
})

const progressWidth = computed(() => {
	const pct = Math.min(95, (elapsedSec.value / 40) * 100)
	return `${pct}%`
})

const dots = computed<Array<{ state: DotState }>>(() => {
	const phase = stepIndex.value
	return STEPS.map((_, i) => ({
		state: i < phase ? 'done' : i === phase ? 'active' : 'pending',
	}))
})

const stepLabel = computed(() => STEPS[stepIndex.value])

const actions = computed<Action[]>(() => {
	switch (status.value) {
		case 'timeout':
			return [
				{
					label:    'Retry verification',
					iconPath: mdiRefresh,
					variant:  'primary',
					handler:  restartVerification,
				},
				{
					label:    'Return to dashboard',
					iconPath: mdiArrowLeft,
					variant:  'ghost',
					handler:  returnToOrigin,
				},
			]
		case 'error':
			return [{
				label:    'Return to dashboard',
				iconPath: mdiArrowLeft,
				variant:  'ghost',
				handler:  returnToOrigin,
			}]
		default:
			return []
	}
})

onMounted(() => {
	if (!paymentContext.transactionToken || !paymentContext.returnTo) {
		handleInvalidReturn()
		return
	}
	startProgress()
	void start()
})

onBeforeUnmount(() => {
	stop()
	stopProgress()
})

async function handleSuccess() {
	stopProgress()
	notifySuccess({ message: 'Payment confirmed' })
	clearPaymentParamsFromUrl()
	window.location.replace(paymentContext.returnTo)
}

async function handleFailure() {
	stopProgress()
	status.value = 'error'
	notifyError({ message: 'Payment failed', important: true })
	redirectWithFailure()
}

async function handleCancelled() {
	stopProgress()
	notifyError({ message: 'Payment was cancelled' })
	clearPaymentParamsFromUrl()
	window.location.replace(paymentContext.returnTo)
}

async function handleTimeout() {
	stopProgress()
	status.value = 'timeout'
	notifyInfo({
		message: 'Verification timed out. Retry or return and check later.',
	})
}

function restartVerification() {
	status.value = 'loading'
	startProgress()
	restart()
}

function returnToOrigin() {
	clearPaymentParamsFromUrl()
	window.location.replace(paymentContext.returnTo)
}

function handleInvalidReturn() {
	clearPaymentParamsFromUrl()
	notifyError({ message: 'Invalid payment return', important: true })
	router.replace({ name: 'DefaultPageErrorExternal' })
}

function redirectWithFailure() {
	clearPaymentParamsFromUrl()
	const url = new URL(paymentContext.returnTo, window.location.origin)
	url.searchParams.set('paymentFailed', 'true')
	window.location.replace(url.toString())
}
</script>

<style lang="scss" scoped>

.payment-return {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 100%;
	padding: 40px 16px;
}

.payment-card {
	width: 100%;
	max-width: 440px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 16px;
	padding: 48px 40px;
	display: flex;
	flex-direction: column;
	align-items: center;
	position: relative;
	overflow: hidden;
}

/* Top accent stripe — colour-coded per state */
.card-accent {
	position: absolute;
	top: 0; left: 0; right: 0;
	height: 2px;
	background: transparent;
	transition: background 0.4s ease;
}

.payment-card.loading  .card-accent { background: var(--color-primary); }
.payment-card.timeout  .card-accent { background: var(--color-warning); }
.payment-card.error    .card-accent { background: var(--color-error); }

.icon-wrap {
	width: 64px;
	height: 64px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 20px;
	position: relative;
	flex-shrink: 0;
}

.icon-wrap.loading {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
	animation: pulse 2.2s ease-in-out infinite;
}

.icon-wrap.timeout {
	background: color-mix(in srgb, var(--color-warning) 12%, transparent);
	color: var(--color-warning);
}

.icon-wrap.error {
	background: var(--color-error-hover);
	color: var(--color-error);
}

/* Icon component inside the wrap */
.icon {
	width: 26px;
	height: 26px;
	stroke-width: 1.75;
}

/* Spinner ring overlaid on loading icon wrap */
.spinner {
	position: absolute;
	inset: 0;
	border-radius: 50%;
	border: 1.5px solid var(--color-border);
	border-top-color: var(--color-primary);
	animation: spin 0.9s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

.badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 11px;
	font-weight: 500;
	padding: 4px 10px;
	border-radius: 99px;
	margin-bottom: 16px;
	letter-spacing: 0.01em;
}

.badge.info    {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.badge.warning {
	background: color-mix(in srgb, var(--color-warning) 12%, transparent);
	color: var(--color-warning-text);
}

.badge.danger  {
	background: var(--color-error-hover);
	color: var(--color-error);
}

.badge-icon {
	width: 11px;
	height: 11px;
	stroke-width: 2;
	flex-shrink: 0;
}

.copy {
	text-align: center;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
}

.heading {
	font-size: 17px;
	font-weight: 500;
	color: var(--color-main-text);
	line-height: 1.35;
}

.sub {
	font-size: 13.5px;
	color: var(--color-text-maxcontrast);
	line-height: 1.65;
	max-width: 300px;
}

.details {
	width: 100%;
	margin-top: 20px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.detail-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.detail-icon {
	width: 14px;
	height: 14px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	stroke-width: 1.75;
}

.detail-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.detail-value {
	font-size: 12px;
	color: var(--color-main-text);
	margin-left: auto;
	font-family: var(--font-face-monospace, monospace);
}

.detail-value.muted  { color: var(--color-text-maxcontrast); }
.detail-value.danger { color: var(--color-error); }

.progress-area {
	width: 100%;
	margin-top: 28px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0;
}

.progress-track {
	width: 100%;
	height: 2px;
	background: var(--color-border);
	border-radius: 2px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background: var(--color-primary);
	border-radius: 2px;
	transition: width 1s linear;
}

.step-row {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.dot {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--color-border-dark);
	transition: background 0.3s ease, transform 0.3s ease;
}

.dot.active {
	background: var(--color-primary);
	transform: scale(1.35);
}

.dot.done {
	background: var(--color-success);
}

.step-label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
	letter-spacing: 0.01em;
}

.actions {
	width: 100%;
	margin-top: 24px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.btn {
	width: 100%;
	height: 38px;
	border-radius: 10px;
	font-size: 14px;
	font-weight: 500;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	transition: background 0.15s, color 0.15s, transform 0.1s;
	font-family: inherit;
}

.btn:active { transform: scale(0.98); }

.btn.primary {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.btn.primary:hover {
	background: var(--color-primary-hover);
}

.btn.ghost {
	background: transparent;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border-dark);
}

.btn.ghost:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.btn-icon {
	width: 15px;
	height: 15px;
	stroke-width: 2;
	flex-shrink: 0;
}

.fade-slide-enter-active,
.fade-slide-leave-active {
	transition: opacity 0.18s ease, transform 0.18s ease;
}

.fade-slide-enter-from {
	opacity: 0;
	transform: translateY(6px);
}

.fade-slide-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

@keyframes pulse {
	0%, 100% { box-shadow: 0 0 0 0 transparent; }
	50%       { box-shadow: 0 0 0 6px var(--color-primary-light); }
}

</style>
