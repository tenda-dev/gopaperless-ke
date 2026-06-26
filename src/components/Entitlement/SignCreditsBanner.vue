<template>
	<div class="sign-credits-banner">

		<!-- Header row: label + live credit pill -->
		<div class="banner-header">
			<span class="banner-label">Signing credits</span>

			<div class="banner-header__badge">
				<div class="credit-pill" :class="hasCredits ? 'credit-pill--filled' : 'credit-pill--empty'">
					<NcIconSvgWrapper :path="mdiLightningBolt" :size="12" />
					<span>{{ remainingUses }} {{ remainingUses === 1 ? 'credit' : 'credits' }}</span>
				</div>
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
					Choose how you'd like to purchase signing credits.
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

						<div class="option-card__body">

							<div class="option-card__title-row">

								<div class="option-card__title">
									<NcIconSvgWrapper
										:path="mdiCreditCardOutline"
										:size="18"
									/>

									<span>Buy required credits</span>
								</div>

							</div>

							<div class="option-card__desc">
								If you only need to sign this document.
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

						<div class="option-card__body">

							<div class="option-card__title-row">

								<div class="option-card__title">
									<NcIconSvgWrapper
										:path="mdiStar"
										:size="18"
									/>

									<span>Purchase signing credits</span>
								</div>

								<span class="option-card__badge">
									Recommended
								</span>

							</div>

							<div class="option-card__desc">
								Purchase extra credits and use them across future document signings.
							</div>

							<div class="option-card__sparkle">

								<NcIconSvgWrapper
									:path="mdiStarCircleOutline"
									:size="32"
								/>

							</div>

						</div>

						<div
							class="option-card__dot"
							:class="{ 'option-card__dot--selected': selectedFlow === PaymentFlowType.CREDIT_PACK }"
						/>
					</label>
				</div>

				<div class="banner-reassurance">
					<div>
						<NcIconSvgWrapper class="banner-reassurance__icon" :path="mdiShieldCheckOutline" :size="16" />
                    </div>

					<div class="banner-reassurance__content">
						After payment, signing will continue automatically.
					</div>
				</div>
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
	mdiStarCircleOutline,
	mdiStar,
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
$accent:        #1D9E75;
$accent-light:  #E1F5EE;
$accent-mid:    #0F6E56;
$accent-dark:   #085041;
$amber-light:   #FAEEDA;
$amber-dark:    #633806;

$spring:  cubic-bezier(.34, 1.56, .64, 1);
$ease-out: cubic-bezier(.4, 0, .2, 1);

.sign-credits-banner {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 14px;
	border-radius: 12px;
	border: 1px solid var(--color-border-dark);
}

.banner-header {
	display: flex;
	align-items: center;
	justify-content: space-between;

	&__badge {
		flex: 0 0 auto;
	}
}

.banner-label {
	font-size: 10px;
	font-weight: 600;
	letter-spacing: .1em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.credit-pill {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	font-weight: 500;
	padding: 0 8px;
	border-radius: 20px;
	transition: background .3s $ease-out, color .3s $ease-out;

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

.option-card {
	position: relative;
	display: flex;
	align-items: center;
	position: relative;
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
		position:relative;

		overflow:hidden;

		background:

			radial-gradient(
				circle at top left,
				rgba($accent,.08),
				transparent 45%
			),

			linear-gradient(
				180deg,
				rgba($accent-light,.65),
				var(--color-main-background)
			);

		border-color:rgba($accent,.25);

		box-shadow:

			0 8px 20px rgba($accent,.08),

			inset 0 1px rgba(255,255,255,.7);

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
		justify-content:space-between;
		gap:12px;
		margin-bottom:6px;
	}

	&__title {
		display:flex;
		align-items:center;
		gap:8px;

		font-size:12.5px;
		font-weight:600;
		color:var(--color-main-text);

		:deep(svg) {
			color:$accent;
			flex-shrink:0;
		}
	}

	&__badge {
		display:inline-flex;
		align-items:center;

		padding:3px 8px;

		border-radius:999px;

		font-size:10px;
		font-weight:700;

		letter-spacing:.02em;

		color:$accent-dark;

		background:rgba($accent,.08);

		white-space:nowrap;
	}

	&__desc {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		line-height: 1.5;
		margin-top: 2px;
		max-width: 90%;
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

	&__sparkle {
		position:absolute;
		top:55px;
		right:15px;
		opacity:.18;
		color:$accent;
		pointer-events:none;
	}

	// Custom radio dot — border-width transition mimics iOS filled dot
	&__dot {
		position: absolute;
		top: 16px;
		right: 13px;
		width: 17px;
		height: 17px;
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

.banner-reassurance {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1.45;
	padding-top: 2px;
	opacity: .75;

	&__icon {
		flex-shrink: 0;
		flex: 0 0 auto;
		:deep(svg) { display: block }
	}

	&__content {
		flex: 1;
		line-height: 1.45;
	}
}

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
