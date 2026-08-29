<template>
	<template v-if="isInitialising">
		<div class="skeleton">
			<div class="skeleton__header">
				<div class="skeleton__label" />
				<div class="skeleton__count" />
			</div>

			<div class="banner-rule" />

			<div class="skeleton__hero">
				<div class="skeleton__icon" />
				<div class="skeleton__title" />
				<div class="skeleton__line skeleton__line--long" />
				<div class="skeleton__line skeleton__line--short" />
			</div>
		</div>
	</template>
	<template v-else>
		<div class="sign-credits-banner">

			<!-- Header row: label + live credit pill -->
			<div class="banner-header">
				<span class="banner-header__label">Certified Signatures</span>

				<span class="banner-header__count" :class="{ 'banner-header__count--empty': !hasCredits }">
					{{ remainingUses }} available
				</span>
			</div>


			<div class="banner-rule" />

			<Transition name="fade-slide">
                <div v-if="isSelfReserved" class="hero">

					<div class="hero__halo">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="24" />
					</div>

					<div class="hero__title">
						Ready to sign
					</div>

					<div class="hero__desc">
						One of your certified signatures has already been reserved for this document.
					</div>

					<div class="hero__reassurance">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="14" />

						<span>
							No further payment is required.
						</span>
					</div>
				</div>
			</Transition>

			<!-- State: sponsored (Focus hero) -->
			<Transition name="fade-slide">
				<div v-if="isRequesterSponsored" class="hero">
					<div class="hero__halo">
						<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="20" />
					</div>

					<div class="hero__title">
						Ready to sign
					</div>

					<div class="hero__desc">
						A certified signature has already been reserved on your behalf.
					</div>

					<div class="hero__sponsor">
						<span class="hero__label">
							Paid for by:
						</span>

						<span class="hero__email">
							{{ sponsorUserId }}
						</span>
					</div>

					<div class="hero__reassurance">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="14" />
						<span>Your available certified signatures won't be affected.</span>
					</div>
				</div>
			</Transition>

			<!-- State: has credits (Focus hero) -->
			<Transition name="fade-slide">
				<div v-if="hasCredits && !hasSigningReservation" class="hero">
					<div class="hero__halo">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="24" />
					</div>

					<div class="hero__title">
						Ready when you are
					</div>

					<div class="hero__desc">
						Signing this document will use one certified signature.
					</div>
				</div>
			</Transition>

			<!-- State: no credits — payment chooser -->
			<Transition name="fade-slide">
				<div v-if="!hasCredits && !hasSigningReservation" class="chooser">
					<p class="chooser__prompt">
						{{ oneTimeSigningEnabled
							? "Choose how you'd like to continue signing."
							: "You need a certified signature to sign this document." }}
					</p>

					<div class="chooser__options">
						<!-- One-time (admin-toggleable) -->
						<label v-if="oneTimeSigningEnabled" class="option"
							:class="{ 'option--selected': selectedFlow === PaymentFlowType.ONE_TIME }">
							<input v-model="selectedFlow" class="option__input" type="radio"
								:value="PaymentFlowType.ONE_TIME">

							<div class="option__main">
								<div class="option__title">
									<span>Buy one certified signature</span>
								</div>
								<div class="option__desc">
									Buy a certified signature for this signing only.
								</div>
							</div>

							<span class="option__dot"
								:class="{ 'option__dot--selected': selectedFlow === PaymentFlowType.ONE_TIME }" />
						</label>

						<!-- Credit pack — preferred -->
						<label class="option"
							:class="{ 'option--selected': selectedFlow === PaymentFlowType.CREDIT_PACK }">
							<input v-model="selectedFlow" class="option__input" type="radio"
								:value="PaymentFlowType.CREDIT_PACK">

							<div class="option__main">
								<div class="option__title">
									<span>Buy certified signatures</span>
									<span v-if="oneTimeSigningEnabled" class="option__tag">
										· recommended
									</span>
								</div>
								<div class="option__desc">
									Buy additional certified signatures for future document signings.
								</div>
							</div>

							<span class="option__dot"
								:class="{ 'option__dot--selected': selectedFlow === PaymentFlowType.CREDIT_PACK }" />
						</label>
					</div>

					<div class="chooser__foot">
						<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="14" />
						<span>After payment, signing will continue automatically.</span>
					</div>
				</div>
			</Transition>
		</div>
	</template>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'

import { loadState } from '@nextcloud/initial-state'

import {
	mdiCheckCircleOutline,
	mdiShieldCheckOutline,
} from '@mdi/js'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { useEntitlementStore } from '@/store/entitlement'
import { DEFAULT_PRODUCT_CODE } from '@/constants/product'
import { PaymentFlowType, usePaymentContextStore } from '@/store/paymentContext'
import { useSignerContextStore } from '@/store/signerContext'
import { useUserContextStore } from '@/store/userContext'

const entitlementStore = useEntitlementStore()
const paymentContext = usePaymentContextStore()
const signerContext = useSignerContextStore()
const userContextStore = useUserContextStore()

// Admin toggle: when disabled, the "Buy just what you need" (one-time) option is
// hidden so users are guided to signing credit packs instead. Defaults to true.
const oneTimeSigningEnabled = (
	loadState('libresign', 'config', {}) as { one_time_signing_enabled?: boolean }
).one_time_signing_enabled ?? true

const entitlement = entitlementStore.getEntitlement(DEFAULT_PRODUCT_CODE)

const remainingUses = computed(() => entitlement.value?.remainingUses ?? 0)
const hasCredits = computed(() => remainingUses.value > 0)

const selectedFlow = computed({
	get: () => paymentContext.flowType,
	set: value => paymentContext.setFlowType(value),
})

const sponsorship = computed(() =>
	entitlementStore.getSponsorship(
		signerContext.signRequestId,
	).value,
)

const hasSigningReservation = computed(
	() => sponsorship.value?.sponsored ?? false,
)

const sponsorUserId = computed(
	() => sponsorship.value?.sponsorUserId,
)

const isSelfReserved = computed(() =>
	hasSigningReservation.value
	&& sponsorUserId.value === userContextStore.uid,
)

const isRequesterSponsored = computed(() =>
	hasSigningReservation.value
	&& sponsorUserId.value !== userContextStore.uid,
)

const isLoading = entitlementStore.isLoading(
	DEFAULT_PRODUCT_CODE,
)

const isSponsorshipLoading =
	entitlementStore.sponsorshipLoadingState(
		signerContext.signRequestId,
	)

const isInitialising = computed(() =>
	isLoading.value || isSponsorshipLoading.value,
)

onMounted(async () => {
	// If one-time signing is disabled, force the credit-pack flow so the hidden
	// option can never remain selected.
	if (!oneTimeSigningEnabled &&
		paymentContext.flowType === PaymentFlowType.ONE_TIME) {
		paymentContext.setFlowType(PaymentFlowType.CREDIT_PACK)
	}
	await Promise.all([
		userContextStore.initialise(),
		entitlementStore.initialise(
			DEFAULT_PRODUCT_CODE,
		),
		entitlementStore.loadSponsorship(
			DEFAULT_PRODUCT_CODE,
			signerContext.signRequestId,
		),
	])
})
</script>

<style scoped lang="scss">
$accent: #1D9E75;
$accent-light: #E1F5EE;
$accent-mid: #0F6E56;
$accent-dark: #085041;

$ease-out: cubic-bezier(.4, 0, .2, 1);

.sign-credits-banner {
	display: flex;
	flex-direction: column;
	padding: 18px;
	border-radius: 12px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.banner-header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;

	&__label {
		font-size: 10px;
		font-weight: 600;
		letter-spacing: .14em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__count {
		font-size: 12px;
		font-weight: 600;
		color: var(--color-main-text);
		font-variant-numeric: tabular-nums;

		&--empty {
			font-weight: 500;
			color: var(--color-text-maxcontrast);
		}
	}
}

.banner-rule {
	height: 1px;
	margin-top: 16px;
	background: var(--color-border);
}

.hero {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 8px;
	margin-top: 14px;
	padding: 6px 4px 2px;

	&__halo {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 42px;
		height: 42px;
		border-radius: 16px;
		background: $accent-light;
		color: $accent-mid;
		box-shadow: 0 0 0 6px rgba($accent, .06);

		:deep(svg) {
			display: block
		}
	}

	&__title {
		font-size: 15px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__desc {
		max-width: 30ch;
		font-size: 12.5px;
		line-height: 1.5;
		color: var(--color-text-lighter);

		strong {
			font-weight: 600;
			color: $accent-dark;
		}
	}

	&__label {
		font-size: 11px;
		color: var(--color-text-maxcontrast);
	}

	&__email {
		margin-top: 2px;
		font-size: 13px;
		font-weight: 600;
		color: $accent-dark;
		word-break: break-word;
	}

	&__reassurance {
		display: inline-flex;
		flex: 0 0 auto;
		align-items: center;
		width: fit-content;
		max-width: 100%;
		gap: 0;
		margin-top: 2px;
		padding: 2px 12px;
		border-radius: 20px;
		font-size: 11.5px;
		color: var(--color-text-maxcontrast);
		background: var(--color-background-hover);

		:deep(svg) {
			display: block;
			flex-shrink: 0;
			color: $accent-mid;
		}
	}
}

.chooser {
	display: flex;
	flex-direction: column;

	&__prompt {
		margin: 14px 0 0;
		font-size: 12.5px;
		line-height: 1.5;
		color: var(--color-text-lighter);
	}

	&__options {
		display: flex;
		flex-direction: column;
		margin-top: 4px;
	}

	&__foot {
		display: flex;
		align-items: center;
		gap: 7px;
		margin-top: 14px;
		font-size: 11px;
		color: var(--color-text-maxcontrast);

		:deep(svg) {
			display: block;
			flex-shrink: 0;
			color: $accent-mid;
			opacity: .8;
		}
	}
}

.option {
	position: relative;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 13px 4px;
	cursor: pointer;
	border-top: 1px solid var(--color-border);

	&:first-child {
		border-top: 0;
	}

	&__input {
		position: absolute;
		opacity: 0;
		pointer-events: none;
	}

	&__main {
		flex: 1;
		min-width: 0;
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 13px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__tag {
		font-size: 10px;
		font-weight: 600;
		letter-spacing: .04em;
		text-transform: uppercase;
		color: $accent-mid;
	}

	&__desc {
		margin-top: 2px;
		font-size: 12px;
		line-height: 1.45;
		color: var(--color-text-maxcontrast);
	}

	&__dot {
		flex-shrink: 0;
		width: 18px;
		height: 18px;
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

.skeleton {
	display: flex;
	flex-direction: column;

	&__header {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}

	&__label,
	&__count,
	&__icon,
	&__title,
	&__line {
		background: var(--color-background-hover);
		animation: skeleton-pulse 1.4s ease-in-out infinite;
	}

	&__label {
		width: 110px;
		height: 10px;
		border-radius: 999px;
	}

	&__count {
		width: 60px;
		height: 12px;
		border-radius: 999px;
	}

	&__hero {
		display: flex;
		flex-direction: column;
		align-items: center;
		margin-top: 18px;
		gap: 10px;
	}

	&__icon {
		width: 42px;
		height: 42px;
		border-radius: 16px;
	}

	&__title {
		width: 140px;
		height: 16px;
		border-radius: 999px;
	}

	&__line {
		height: 12px;
		border-radius: 999px;

		&--long {
			width: 220px;
		}

		&--short {
			width: 160px;
		}
	}
}

@keyframes skeleton-pulse {
	0% {
		opacity: .45;
	}

	50% {
		opacity: .9;
	}

	100% {
		opacity: .45;
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
