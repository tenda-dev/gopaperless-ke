<template>
	<div class="sign-credits-banner">

		<!-- Header row: label + live credit pill -->
		<div class="banner-header">
			<span class="banner-label">Signing credits</span>

			<div class="credit-pill" :class="hasCredits ? 'credit-pill--filled' : 'credit-pill--empty'">
				<NcIconSvgWrapper :path="mdiLightningBolt" :size="12" />
				<span>{{ remainingUses }} {{ remainingUses === 1 ? 'credit' : 'credits' }}</span>
			</div>
		</div>

		<!-- State: has credits -->
		<Transition name="fade-slide">
			<div v-if="hasCredits" class="has-credits-card">
				<div class="has-credits-card__icon">
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="18" />
				</div>
				<p class="has-credits-card__text">
					This document will use
					<strong>1 of your {{ remainingUses }} signing {{ remainingUses === 1 ? 'credit' : 'credits' }}</strong>.
				</p>
			</div>
		</Transition>

		<!-- State: no credits — payment chooser -->
		<Transition name="fade-slide">
			<div v-if="!hasCredits" class="payment-chooser">
				<p class="payment-chooser__prompt">
					Choose how to complete this signing.
				</p>

				<div class="option-cards">
					<!-- One-time payment -->
					<label
						class="option-card"
						:class="{ 'option-card--selected': selectedFlow === PaymentFlowType.ONE_TIME }"
						@mousedown="onCardPress"
						@mouseup="onCardRelease"
						@mouseleave="onCardRelease"
					>
						<input
							v-model="selectedFlow"
							class="option-card__radio"
							type="radio"
							:value="PaymentFlowType.ONE_TIME"
						>

						<div
							class="option-card__icon"
							:class="{ 'option-card__icon--active': selectedFlow === PaymentFlowType.ONE_TIME }"
						>
							<NcIconSvgWrapper :path="mdiCreditCardOutline" :size="18" />
						</div>

						<div class="option-card__body">
							<div class="option-card__title">
								Pay for this signature
							</div>
							<div class="option-card__desc">
								One-time payment. No commitment.
							</div>
						</div>

						<div
							class="option-card__dot"
							:class="{ 'option-card__dot--selected': selectedFlow === PaymentFlowType.ONE_TIME }"
						/>
					</label>

					<!-- Credit pack — preferred -->
					<label
						class="option-card option-card--preferred"
						:class="{ 'option-card--selected': selectedFlow === PaymentFlowType.CREDIT_PACK }"
						@mousedown="onCardPress"
						@mouseup="onCardRelease"
						@mouseleave="onCardRelease"
					>
						<input
							v-model="selectedFlow"
							class="option-card__radio"
							type="radio"
							:value="PaymentFlowType.CREDIT_PACK"
						>

						<div
							class="option-card__icon"
							:class="{ 'option-card__icon--active': selectedFlow === PaymentFlowType.CREDIT_PACK }"
						>
							<NcIconSvgWrapper :path="mdiCartOutline" :size="18" />
						</div>

						<div class="option-card__body">
							<div class="option-card__title-row">
								<span class="option-card__title">Buy signing credits</span>
								<span class="option-card__badge" v-if="offersEnabled">Save 20%</span>
							</div>
							<div class="option-card__desc">
								Sign this and future documents instantly, no prompts.
							</div>
							<!-- Price row: shown now, discount slot ready for later -->
							<div class="option-card__price" v-if="offersEnabled">
								<span class="option-card__price-was">Ksh 500</span>
								<span class="option-card__price-now">Ksh 400 / credit</span>
							</div>
						</div>

						<div
							class="option-card__dot"
							:class="{ 'option-card__dot--selected': selectedFlow === PaymentFlowType.CREDIT_PACK }"
						/>
					</label>
				</div>

				<p class="banner-reassurance">
					<NcIconSvgWrapper class="banner-reassurance__icon" :path="mdiShieldCheckOutline" :size="13" />
					After purchase, signing continues automatically using your new balance.
				</p>
			</div>
		</Transition>

	</div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'

import {
	mdiCartOutline,
	mdiCreditCardOutline,
	mdiLightningBolt,
	mdiCheckCircleOutline,
	mdiShieldCheckOutline,
} from '@mdi/js'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { useEntitlementStore } from '@/store/entitlement'
import { DEFAULT_PRODUCT_CODE } from '@/constants/product'
import { PaymentFlowType, usePaymentContextStore } from '@/store/paymentContext'

const entitlementStore = useEntitlementStore()
const paymentContext = usePaymentContextStore()

const entitlement = entitlementStore.getEntitlement(DEFAULT_PRODUCT_CODE)

const remainingUses = computed(() => entitlement.value?.remainingUses ?? 0)
const hasCredits = computed(() => remainingUses.value > 0)
const offersEnabled = computed(() => false)

const selectedFlow = computed({
	get: () => paymentContext.flowType,
	set: value => paymentContext.setFlowType(value),
})

// Press-scale physics — applied via a transient class on the card element
function onCardPress(e: MouseEvent) {
	const card = (e.currentTarget as HTMLElement)
	card.classList.add('option-card--pressed')
}

function onCardRelease(e: MouseEvent) {
	const card = (e.currentTarget as HTMLElement)
	card.classList.remove('option-card--pressed')
}

onMounted(async () => {
	await entitlementStore.initialise(DEFAULT_PRODUCT_CODE)
})
</script>

<style scoped lang="scss">
// ─── Design tokens ────────────────────────────────────────────────────────────
// Teal accent — matches GoPaperless signing success colour.
// Centralised here so swapping for a CSS custom-prop later is one edit.
$accent:        #1D9E75;
$accent-light:  #E1F5EE;
$accent-mid:    #0F6E56;
$accent-dark:   #085041;
$amber-light:   #FAEEDA;
$amber-dark:    #633806;

// ─── Easing ───────────────────────────────────────────────────────────────────
$spring:  cubic-bezier(.34, 1.56, .64, 1);
$ease-out: cubic-bezier(.4, 0, .2, 1);

// ─── Root ─────────────────────────────────────────────────────────────────────
.sign-credits-banner {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 14px;
	border-radius: 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border-dark);
}

// ─── Header ───────────────────────────────────────────────────────────────────
.banner-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.banner-label {
	font-size: 10px;
	font-weight: 600;
	letter-spacing: .1em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

// ─── Credit pill ──────────────────────────────────────────────────────────────
.credit-pill {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	font-weight: 500;
	padding: 3px 8px;
	border-radius: 20px;
	transition: background .3s $ease-out, color .3s $ease-out;

	// NcIconSvgWrapper renders an <svg> — nudge it into vertical alignment
	:deep(svg) {
		display: block;
		flex-shrink: 0;
	}

	&--empty {
		background: $amber-light;
		color: $amber-dark;
	}

	&--filled {
		background: $accent-light;
		color: $accent-dark;
	}
}

// ─── Has-credits card ─────────────────────────────────────────────────────────
.has-credits-card {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 11px 13px;
	border-radius: 10px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);

	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border-radius: 8px;
		background: $accent-light;
		color: $accent-mid;
		flex-shrink: 0;

		:deep(svg) { display: block }
	}

	&__text {
		font-size: 12px;
		line-height: 1.45;
		color: var(--color-text-lighter);

		strong {
			font-weight: 500;
			color: $accent-dark;
		}
	}
}

// ─── Payment chooser ──────────────────────────────────────────────────────────
.payment-chooser {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__prompt {
		font-size: 12px;
		color: var(--color-text-lighter);
		line-height: 1.4;
	}
}

.option-cards {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

// ─── Option card ──────────────────────────────────────────────────────────────
.option-card {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 11px;
	padding: 11px 13px;
	border-radius: 10px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	cursor: pointer;

	// Smooth entry + hover
	transition:
		border-color .2s $ease-out,
		background  .2s $ease-out,
		transform   .12s $spring;

	&:hover {
		border-color: var(--color-border-maxcontrast);
	}

	// Press physics
	&--pressed {
		transform: scale(0.975);
	}

	// Selected state
	&--selected {
		border-color: $accent;
		background: rgba($accent-light, .25);

		&:hover {
			border-color: $accent;
		}
	}

	// Preferred card: teal top-edge accent
	&--preferred {
		border-top: 2px solid $accent;
		padding-top: 10px; // compensate for the extra border-top px
	}

	// Hide the native radio — selection driven by classes
	&__radio {
		position: absolute;
		opacity: 0;
		pointer-events: none;
	}

	// Icon capsule
	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 30px;
		height: 30px;
		border-radius: 7px;
		background: var(--color-background-hover);
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
		transition:
			background .2s $ease-out,
			color      .2s $ease-out,
			transform  .3s $spring;

		:deep(svg) { display: block }

		&--active {
			background: $accent-light;
			color: $accent-mid;
			transform: scale(1.1);
		}
	}

	// Body
	&__body {
		flex: 1;
		min-width: 0;
		padding-right: 20px; // clearance for the dot indicator
	}

	&__title-row {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-bottom: 2px;
	}

	&__title {
		font-size: 13px;
		font-weight: 500;
		color: var(--color-main-text);
	}

	&__badge {
		font-size: 10px;
		font-weight: 500;
		padding: 1px 6px;
		border-radius: 20px;
		background: $accent-light;
		color: $accent-dark;
		flex-shrink: 0;
	}

	&__desc {
		font-size: 11.5px;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
		margin-top: 1px;
	}

	// Price row — hidden when no discount data; show by default for now
	&__price {
		display: flex;
		align-items: center;
		gap: 5px;
		margin-top: 5px;
	}

	&__price-was {
		font-size: 11px;
		color: var(--color-text-maxcontrast);
		text-decoration: line-through;
		opacity: .6;
	}

	&__price-now {
		font-size: 12px;
		font-weight: 500;
		color: $accent-mid;
	}

	// Custom radio dot — border-width transition mimics iOS filled dot
	&__dot {
		position: absolute;
		top: 13px;
		right: 13px;
		width: 15px;
		height: 15px;
		border-radius: 50%;
		border: 1.5px solid var(--color-border-maxcontrast);
		background: var(--color-main-background);
		transition:
			border-color .15s $ease-out,
			border-width .15s $ease-out;

		&--selected {
			border-color: $accent;
			border-width: 5px;
		}
	}
}

// ─── Reassurance line ─────────────────────────────────────────────────────────
.banner-reassurance {
	display: flex;
	align-items: flex-start;
	gap: 5px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1.45;
	padding-top: 2px;
	opacity: .75;

	&__icon {
		flex-shrink: 0;
		margin-top: 1px;
		:deep(svg) { display: block }
	}
}

// ─── State transitions ────────────────────────────────────────────────────────
.fade-slide-enter-active {
	transition: opacity .3s $ease-out, transform .3s $ease-out;
}

.fade-slide-leave-active {
	transition: opacity .2s $ease-out, transform .2s $ease-out;
	position: absolute;
	width: 100%;
}

.fade-slide-enter-from {
	opacity: 0;
	transform: translateY(6px);
}

.fade-slide-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}
</style>
