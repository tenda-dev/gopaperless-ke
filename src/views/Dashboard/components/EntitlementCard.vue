<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!-- COMPACT STRIP (mobile ≤768) -->
	<div v-if="compact" class="entitlement-strip">
		<div v-if="loading" class="strip-loading">
			Loading credits…
		</div>

		<template v-else>
			<div class="strip-icon">
				<NcIconSvgWrapper :path="cardIcon" :size="20" />
			</div>

			<div class="strip-body">
				<span class="strip-num">{{ remainingUses }}</span>
				<span class="strip-label">credits remaining</span>
			</div>

			<div class="entitlement-cta-button strip-cta">
				<NcButton @click="handleCtaClick" variant="tertiary">
					Top up
				</NcButton>
			</div>
		</template>

		<CreditPurchaseFlow
			v-if="showPurchaseFlow"
			product-code="SIGN_DOCUMENT"
			payment-purpose="credit_purchase"
			:allow-quantity-selection="true"
			:presentation="purchasePresentation"
			@success="handlePurchaseSuccess"
			@close="showPurchaseFlow = false"
		/>
	</div>

	<!-- FULL CARD (desktop) -->
	<div v-else class="card entitlement-card checkout-active">
		<!-- loading -->
		<div v-if="loading">
			Loading credits...
		</div>

		<!-- loaded -->
		<template v-else>
			<div class="entitlement-icon-bg">
				<NcIconSvgWrapper :path="cardIcon" :size="72" />
			</div>

			<div class="entitlement-content">
				<h2>{{ remainingUses }}</h2>

				<p class="label">
					Credits remaining
				</p>

				<p class="description">
					Use credits to sign documents instantly
				</p>

				<div class="entitlement-cta-button">
					<NcButton @click="handleCtaClick" variant="tertiary" :wide="true">
						Top up
					</NcButton>
				</div>
			</div>
		</template>

		<CreditPurchaseFlow
			v-if="showPurchaseFlow"
			product-code="SIGN_DOCUMENT"
			payment-purpose="credit_purchase"
			:allow-quantity-selection="true"
			:presentation="purchasePresentation"
			@success="handlePurchaseSuccess"
			@close="showPurchaseFlow = false"
		/>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { CREDIT_PURCHASE_PRESENTATION } from '@/constants/purchasePresentation'
import NcButton from '@nextcloud/vue/components/NcButton'
import CreditPurchaseFlow from '@/components/Payments/CreditPurchaseFlow.vue'

import { useEntitlementStore } from '@/store/entitlement'

import { DEFAULT_PRODUCT_CODE } from '@/constants/product'

import {
	mdiCartOutline,
	mdiCreditCardOutline
} from '@mdi/js'
import type { PaymentPresentation } from '@/payment'

withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })

const purchasePresentation = CREDIT_PURCHASE_PRESENTATION

const entitlementStore =
	useEntitlementStore()

const showPurchaseFlow =
	ref(false)

const loading =
	entitlementStore.isLoading(
		DEFAULT_PRODUCT_CODE,
	)

const remainingUses =
	entitlementStore.getRemainingUses(
		DEFAULT_PRODUCT_CODE,
	)

const entitlement =
	entitlementStore.getEntitlement(
		DEFAULT_PRODUCT_CODE,
	)

const cardIcon = computed(() => {
	return mdiCreditCardOutline
})

async function handlePurchaseSuccess() {
	showPurchaseFlow.value = false

	await entitlementStore.refresh(
		DEFAULT_PRODUCT_CODE,
	)
}

function handleCtaClick() {
	showPurchaseFlow.value = true
}

onMounted(async () => {
	await entitlementStore.initialise(
		DEFAULT_PRODUCT_CODE,
	)
})
</script>

<style lang="scss" scoped>
.entitlement-card {
	padding: 18px;
	position: relative;
	overflow: hidden;
	min-height: 184px;
	display: flex;
	align-items: flex-start;
	gap: 14px;
	border-radius: 12px;

	background:
		linear-gradient(135deg,
			#0F172A 0%,
			#111827 100%);

	transition:
		background 220ms ease,
		transform 220ms ease,
		box-shadow 220ms ease;

	box-shadow: 0 8px 20px rgba(15, 23, 42, 0.25);
	color: white;
}

.entitlement-card.checkout-active {
	background:
		linear-gradient(135deg,
			#052e1b 0%,
			#065f46 100%);

	box-shadow:
		0 10px 28px rgba(4, 213, 109, 0.22);
	transform: translateY(-1px);
}

.entitlement-card.checkout-active .entitlement-icon-bg {
	opacity: 0.22;

	filter:
		drop-shadow(0 0 18px rgba(4, 213, 109, 0.25));
}

.entitlement-icon-bg {
	position: absolute;
	left: -40px;
	top: 50%;
	transform: translateY(-50%);

	width: 110px;
	height: 110px;

	border-radius: 20px;

	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0.15;
	transition:
		opacity 220ms ease,
		filter 220ms ease;
}

.entitlement-content {
	margin-left: 60px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	--color-main-text: #fff;

	& :deep(.button-vue--primary) {
		margin-top: 10px;
		align-self: flex-start;

		// background: #04D56D;
		border: none;
		// color: #0F172A;
	}
}

.entitlement-content h2 {
	margin-top: 0;
	font-size: 22px;
	font-weight: 700;
}

.entitlement-content .label {
	font-size: 13px;
	opacity: 0.8;
}

.entitlement-content .description {
	font-size: 12px;
	opacity: 0.6;
}


.change-selection {
	font-size: 12px;
	font-weight: 600;

	color: rgba(255, 255, 255, 0.82);

	cursor: pointer;

	transition:
		opacity 180ms ease,
		transform 180ms ease;

	&:hover {
		opacity: 1;
		transform: translateX(1px);
	}
}

.entitlement-cta-button {
	&:deep(.button-vue--tertiary) {
		background: #04D56D;
		color: #0F172A;
		font-weight: 600;

		box-shadow: 0 4px 10px rgba(4, 213, 109, 0.25);
	}

	&:deep(.button-vue--tertiary:hover) {
		transform: translateY(-1px);
		box-shadow: 0 6px 14px rgba(4, 213, 109, 0.35);
	}
}

// COMPACT MOBILE STRIP

.entitlement-strip {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 64px;
	padding: 12px 14px;
	border-radius: 14px;
	color: #fff;
	--color-main-text: #fff;
	background: linear-gradient(120deg, #052E1B 0%, #0A7C46 100%);
	box-shadow: 0 8px 18px rgba(4, 120, 70, 0.28);
}

.strip-icon {
	width: 28px;
	height: 28px;
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 8px;
	background: rgba(255, 255, 255, 0.14);
}

.strip-body {
	display: flex;
	align-items: baseline;
	gap: 6px;
	min-width: 0;
}

.strip-num {
	font-size: 18px;
	font-weight: 700;
	line-height: 1;
}

.strip-label {
	font-size: 12px;
	opacity: 0.8;
}

.strip-cta {
	margin-left: auto;
}

.strip-loading {
	font-size: 13px;
	opacity: 0.85;
}
</style>
