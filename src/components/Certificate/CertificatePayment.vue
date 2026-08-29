<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		v-if="open"
		:name="presentation.modalTitle"
		size="normal"
		@closing="handleClose">

		<div class="cert-pay">

			<!-- Pricing load -->
			<div v-if="isLoading" class="cert-pay__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<!--
				Post-payment: the money moved, so we never fall back to a
				payment error here. While the store refresh runs we show a
				sync state; if it fails, a dedicated retryable state.
			-->
			<section v-else-if="syncState !== 'idle'" class="cert-pay__sync">
				<div class="cert-pay__sync-icon" :class="{ 'cert-pay__sync-icon--error': syncState === 'error' }">
					<NcLoadingIcon v-if="syncState === 'syncing'" :size="28" />
					<NcIconSvgWrapper v-else :path="mdiCheckCircleOutline" :size="28" />
				</div>

				<h3 class="cert-pay__sync-title">
					{{ t('libresign', 'Payment received') }}
				</h3>

				<p class="cert-pay__sync-body">
					<template v-if="syncState === 'syncing'">
						{{ t('libresign', 'Setting up your signing certificate…') }}
					</template>
					<template v-else>
						{{ t('libresign', 'Your payment went through. We\'re still activating your certificate — this can take a moment.') }}
					</template>
				</p>

				<button
					v-if="syncState === 'error'"
					type="button"
					class="cert-cta"
					@click="runRefresh">
					<span class="cert-cta__label">{{ t('libresign', 'Retry') }}</span>
				</button>
			</section>

			<template v-else>
				<!-- Step 1 — intent -->
				<section v-show="step === 'intent'" class="cert-pay__intent">
					<CertificateCard
						state="unissued"
						:holder-name="holderName" />

					<div class="cert-pay__copy">
						<h3 class="cert-pay__heading">
							{{ t('libresign', 'Issue your certificate') }}
						</h3>
						<p class="cert-pay__body">
							{{ t('libresign', 'This is what binds your name to a signature. Without it, anyone can verify that a document was signed, but not that you signed it.') }}
						</p>
					</div>

					<!-- Full-width product row, top/bottom light borders -->
					<div class="cert-pay__product">
						<div class="cert-pay__product-info">
							<span class="cert-pay__product-title">
								{{ t('libresign', 'Personal Digital Certificate') }}
							</span>
							<span class="cert-pay__product-sub">
								{{ t('libresign', 'One-time charge · 12 months of access') }}
							</span>
						</div>
						<span v-if="priceLabel" class="cert-pay__product-price">
							{{ priceLabel }}
						</span>
					</div>

					<!-- Wide CTA, mirrors PaymentStep's primary button -->
					<button
						type="button"
						class="cert-cta"
						@click="goToPayment">
						<span class="cert-cta__label">{{ t('libresign', 'Continue') }}</span>
						<span class="cert-cta__icon" aria-hidden="true">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<line x1="5" y1="12" x2="19" y2="12" />
								<polyline points="12,5 19,12 12,19" />
							</svg>
						</span>
					</button>

					<div class="cert-pay__secured">
						<p>
							<NcIconSvgWrapper :path="mdiLockOutline" :size="11" />
							<span>{{ t('libresign', 'Secured payment') }}</span>
						</p>
					</div>
				</section>

				<!-- Step 2 — payment (kept mounted so back navigation preserves state) -->
				<div v-show="step === 'payment'" class="cert-pay__payment">
					<div class="cert-pay__back-wrapper">
						<button
							v-if="canGoBack"
							type="button"
							class="cert-pay__back"
							@click="goToIntent">
							{{ t('libresign', '← Back') }}
						</button>
					</div>

					<PaymentStep
						payment-purpose="certificate_purchase"
						:product-code="PRODUCT_CODE"
						:pricing="pricing"
						:quantity="1"
						:presentation="presentation"
						@payment-success="onPaymentSuccess"
						@state-change="onStateChange"
						@payment-runtime-invalid="onRuntimeInvalid" />
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'

import { mdiCheckCircleOutline, mdiLockOutline } from '@mdi/js'

import { t } from '@nextcloud/l10n'

import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import CertificateCard from './CertificateCard.vue'
import PaymentStep from '@/components/Payments/PaymentStep.vue'

import { useUserContextStore } from '@/store/userContext'
import { useProductPricing } from '@/composables/useProductPricing'
import { CERTIFICATE_PRODUCT_CODE } from '@/constants/product'
import { CERTIFICATE_PURCHASE_PRESENTATION } from '@/constants/purchasePresentation'
import { notifyInfo } from '@/services/toast'
import { type PaymentState } from '@/payment/usePayment'

type CertificateStep = 'intent' | 'payment'
type SyncState = 'idle' | 'syncing' | 'error'

const props = defineProps<{
	open: boolean
}>()

const emit = defineEmits([
	'update:open',
	'paid',
])

const PRODUCT_CODE = CERTIFICATE_PRODUCT_CODE
const presentation = CERTIFICATE_PURCHASE_PRESENTATION

const userContext = useUserContextStore()
const pricing = useProductPricing(PRODUCT_CODE)

const holderName = computed(() => userContext.displayName)

const isLoading = computed(() => pricing.loadingProduct.value)

/**
 * Price comes from the CERTIFICATE_ACCESS product record, never a
 * hardcoded constant. Whole amounts render without decimals (KES 300).
 */
const priceLabel = computed(() => {
	const currency = pricing.currency.value
	const product = pricing.product.value
	if (!currency || !product) {
		return ''
	}
	const value = product.amount / 100
	const amount = Number.isInteger(value) ? String(value) : value.toFixed(2)
	return `${currency} ${amount}`
})

const step = ref<CertificateStep>('intent')

function goToPayment(): void {
	step.value = 'payment'
}

function goToIntent(): void {
	step.value = 'intent'
}

// Track PaymentStep state to block dismissal / back mid-charge.
const currentState = ref<PaymentState>('idle')

const ACTIVE_PHASES: PaymentState[] = [
	'hydrating',
	'initiating',
	'requesting',
	'processing',
]

const isActivePhase = computed(() => ACTIVE_PHASES.includes(currentState.value))
const canGoBack = computed(() => !isActivePhase.value)

function onStateChange(state: PaymentState): void {
	currentState.value = state
}

function handleClose(): void {
	if (isActivePhase.value) {
		notifyInfo({
			message: t('libresign', 'You are in the middle of an active payment session'),
		})
		return
	}

	emit('update:open', false)
}

function onRuntimeInvalid(): void {
	emit('update:open', false)
}

/**
 * Post-payment sync.
 *
 * The paywall is server-authoritative, so we must NOT clear it on the
 * assumption that payment implies access — we query /account/me.
 */
const syncState = ref<SyncState>('idle')

function onPaymentSuccess(): void {
	runRefresh()
}

async function runRefresh(): Promise<void> {
	syncState.value = 'syncing'

	try {
		// refresh(), not initialise(): bypasses the initialised guard and
		// is non-destructive on failure, so a transient issue after a
		// successful charge never wipes the session.
		await userContext.refresh()

		emit('paid')
		emit('update:open', false)
	} catch {
		// The charge succeeded but access hasn't synced. Stay open in a
		// retryable state; never surface this as a payment failure.
		syncState.value = 'error'
	}
}
</script>

<style lang="scss" scoped>
.cert-pay {
	display: flex;
	flex-direction: column;
	padding: 20px;

	&__loading {
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: 200px;
	}

	&__intent {
		display: flex;
		flex-direction: column;
		gap: 18px;
	}

	&__copy {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__heading {
		margin: 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__body {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
	}

	&__product {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;

		padding: 14px 2px;

		border-top: 1px solid var(--color-border);
		border-bottom: 1px solid var(--color-border);
	}

	&__product-info {
		display: flex;
		flex-direction: column;
		gap: 3px;
	}

	&__product-title {
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__product-sub {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__product-price {
		font-size: 18px;
		font-weight: 700;
		color: var(--color-main-text);
	}

	&__payment {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__back-wrapper {
		margin: 0 30px;
	}

	&__back {
		align-self: flex-start;

		padding: 4px 6px;

		font-size: 13px;
		font-weight: 500;

		color: var(--color-text-maxcontrast);
		background: transparent;
		border: none;
		cursor: pointer;

		&:hover {
			color: var(--color-main-text);
		}
	}

	&__secured {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 6px;

		margin: 0;

		font-size: 11.5px;
		letter-spacing: 0.02em;
		color: #cbd5e1;
		opacity: 0.8;

		:deep(svg) {
			color: #cbd5e1;
		}

		p {
			display: flex;
			align-items: center;
		}
	}

	&__sync {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 12px;

		padding: 16px 8px;
	}

	&__sync-icon {
		display: flex;
		align-items: center;
		justify-content: center;

		width: 52px;
		height: 52px;

		border-radius: 14px;
		color: var(--color-success);
		background: color-mix(in srgb, var(--color-success) 12%, transparent);

		&--error {
			color: var(--color-warning);
			background: color-mix(in srgb, var(--color-warning) 14%, transparent);
		}
	}

	&__sync-title {
		margin: 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__sync-body {
		margin: 0;
		max-width: 36ch;
		font-size: 13.5px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
	}
}

.cert-cta {
	--green: #04d56d;
	--green-dark: #073d1f;
	--radius-md: 10px;
	--transition-snappy: 180ms cubic-bezier(0.4, 0, 0.2, 1);

	width: 100%;

	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;

	padding: 12px 20px;

	font-size: 13.5px;
	font-weight: 600;
	letter-spacing: 0.01em;

	color: var(--green-dark);
	background: var(--green);

	border: none;
	border-radius: var(--radius-md);
	cursor: pointer;

	position: relative;
	overflow: hidden;

	transition: all var(--transition-snappy);

	&__icon {
		display: flex;
		align-items: center;
		transition: transform var(--transition-snappy);
	}

	&:hover {
		background: #05e87a;
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(4, 213, 109, 0.35);
	}

	&:hover &__icon {
		transform: translateX(3px);
	}

	&:active {
		transform: scale(0.98) translateY(0);
		box-shadow: none;
	}
}
</style>
