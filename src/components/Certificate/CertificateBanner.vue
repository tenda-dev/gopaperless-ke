<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="cert-banner" role="region" aria-label="Signing certificate setup">
		<div class="cert-banner__inner">
			<div class="cert-banner__icon" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiCertificateOutline" :size="20" />
			</div>

			<div class="cert-banner__text">
				<span class="cert-banner__title">
					Activate your signing certificate
				</span>
				<span class="cert-banner__sub">
					<template v-if="priceLabel">
						One-time {{ priceLabel }} to start signing documents
					</template>
					<template v-else>
						A one-time setup is needed before you can sign documents
					</template>
				</span>
			</div>

			<button
				type="button"
				class="cert-banner__cta"
				@click="emit('activate')">
				Set up
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { mdiCertificateOutline } from '@mdi/js'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { useProductPricing } from '@/composables/useProductPricing'
import { CERTIFICATE_PRODUCT_CODE } from '@/constants/product'

const emit = defineEmits([
	'activate',
])

// Cached by getProductByCode; sharing the code with the modal is cheap.
const pricing = useProductPricing(CERTIFICATE_PRODUCT_CODE)

const priceLabel = computed(() => {
	const currency = pricing.currency.value
	if (!currency) {
		return ''
	}
	return `${currency} ${(pricing.unitPrice.value / 100).toFixed(2)}`
})
</script>

<style lang="scss" scoped>
.cert-banner {
	position: fixed;
	inset-inline: 0;
	bottom: 0;
	z-index: 1400;

	display: flex;
	justify-content: center;

	padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));

	pointer-events: none;

	&__inner {
		pointer-events: auto;

		display: flex;
		align-items: center;
		gap: 14px;

		width: 100%;
		max-width: 560px;

		padding: 12px 14px;

		border-radius: 14px;
		background: #ffffff;
		border: 1px solid rgba(15, 23, 42, 0.08);
		box-shadow: 0 8px 28px rgba(15, 23, 42, 0.14);
	}

	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;

		width: 38px;
		height: 38px;

		border-radius: 11px;
		color: var(--color-primary-element, #1a73e8);
		background: rgba(26, 115, 232, 0.08);
	}

	&__text {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
		flex: 1 1 auto;
	}

	&__title {
		font-size: 14px;
		font-weight: 600;
		letter-spacing: -0.005em;
		color: #0f172a;
	}

	&__sub {
		font-size: 12.5px;
		line-height: 1.35;
		color: #64748b;
	}

	&__cta {
		flex: 0 0 auto;

		padding: 8px 18px;

		font-size: 13px;
		font-weight: 600;

		color: #ffffff;
		background: var(--color-primary-element, #1a73e8);
		border: none;
		border-radius: 10px;
		cursor: pointer;

		transition: filter 0.15s ease;

		&:hover {
			filter: brightness(0.95);
		}
	}
}
</style>
