<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Product pricing')"
		:description="t('libresign', 'Default currency and prices used when products are created automatically. Prices are stored as minor amounts (cents).')">
		<fieldset id="settings-product-pricing" class="product-pricing__sub-section">
			<NcTextField v-model="currency"
				type="text"
				class="product-pricing__input"
				:label="t('libresign', 'Default currency')"
				:placeholder="t('libresign', 'Default currency')"
				@update:modelValue="saveCurrency" />
			<NcTextField v-model="signDocumentPrice"
				type="number"
				class="product-pricing__input"
				:label="t('libresign', 'Sign document price (cents)')"
				:placeholder="t('libresign', 'Sign document price (cents)')"
				@update:modelValue="saveSignDocumentPrice" />
			<NcTextField v-model="certificateAccessPrice"
				type="number"
				class="product-pricing__input"
				:label="t('libresign', 'Certificate access price (cents)')"
				:placeholder="t('libresign', 'Certificate access price (cents)')"
				@update:modelValue="saveCertificateAccessPrice" />
		</fieldset>
	</NcSettingsSection>
</template>
<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import type { AdminInitialState } from '../../types'

defineOptions({
	name: 'ProductPricingSettings',
})

const currency = ref(loadState<AdminInitialState['product_default_currency']>('libresign', 'product_default_currency', 'KES'))
const signDocumentPrice = ref(loadState<AdminInitialState['product_sign_document_price']>('libresign', 'product_sign_document_price', 8000))
const certificateAccessPrice = ref(loadState<AdminInitialState['product_certificate_access_price']>('libresign', 'product_certificate_access_price', 30000))

function saveCurrency() {
	const parsed = String(currency.value).trim() || 'KES'
	currency.value = parsed
	OCP.AppConfig.setValue('libresign', 'product_default_currency', parsed)
}

function saveSignDocumentPrice() {
	const parsed = Math.max(1, Math.floor(Number(signDocumentPrice.value) || 0))
	signDocumentPrice.value = parsed
	OCP.AppConfig.setValue('libresign', 'product_sign_document_price', String(parsed))
}

function saveCertificateAccessPrice() {
	const parsed = Math.max(1, Math.floor(Number(certificateAccessPrice.value) || 0))
	certificateAccessPrice.value = parsed
	OCP.AppConfig.setValue('libresign', 'product_certificate_access_price', String(parsed))
}

defineExpose({
	currency,
	signDocumentPrice,
	certificateAccessPrice,
	saveCurrency,
	saveSignDocumentPrice,
	saveCertificateAccessPrice,
})
</script>
<style scoped lang="scss">
.product-pricing__sub-section {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.product-pricing__input {
	max-width: 240px;
}
</style>
