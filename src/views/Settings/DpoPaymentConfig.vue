
<template>
	<NcSettingsSection :name="name" :description="description">
		<div class="row">
			<NcTextArea v-model="endpoint"
						:label="t('libresign', 'DPO Endpoint')"
						:placeholder="t('libresign', 'https://api.dpo.example.com/')"
						@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="companyToken"
						:label="t('libresign', 'Company Token')"
						type="password"
						:placeholder="companyTokenSet ? t('libresign', '••••••••') : t('libresign', 'Your DPO Company Token')"
						@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="serviceId"
						:label="t('libresign', 'Service ID')"
						:placeholder="t('libresign', 'Your Service ID')"
						@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="paymentUrl"
						:label="t('libresign', 'Payment URL')"
						:placeholder="t('libresign', 'https://pay.dpo.example.com/')"
						@change="saveConfig" />
		</div>
		<div class="row">
			<NcTextArea v-model="goPaperlessCallbackUrl"
						:label="t('libresign', 'GoPaperless Callback Base Url (shared with Daraja)')"
						type="text"
						:placeholder="t('libresign', 'https://your-domain.example.com/webhooks/gopaperless')"
						@change="saveConfig" />
		</div>
	</NcSettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { showError, showSuccess } from '@/services/toast'

export default {
	name: 'DpoPaymentConfig',
	components: {
		NcSettingsSection,
		NcTextArea,
	},
	data() {
		return {
			name: t('libresign', 'DPO Configuration'),
			description: t('libresign', 'Configure Direct Pay Online (DPO) payment settings.'),

			endpoint: loadState('libresign', 'dpo_endpoint', ''),
			companyTokenSet: loadState('libresign', 'dpo_company_token_set', false),
			companyToken: '',
			serviceId: loadState('libresign', 'dpo_service_id', ''),
			paymentUrl: loadState('libresign', 'dpo_payment_url', ''),
			goPaperlessCallbackUrl: loadState('libresign', 'gopaperless_callback_base_url', ''),
		}
	},
	methods: {
		t,
		async saveConfig() {
			try {
				const response = await axios.post(
					generateOcsUrl('/apps/libresign/api/v1/admin/dpo-config'),
					{
						endpoint: this.endpoint,
						companyToken: this.companyToken,
						serviceId: this.serviceId,
						paymentUrl: this.paymentUrl,
						callbackBaseUrl: this.goPaperlessCallbackUrl,
					}
				)

				if (response.data.ocs?.data?.error) {
					showError(t('libresign', 'Failed to save DPO configuration: {error}', {
						error: response.data.ocs.data.error,
					}))
					return
				}

				showSuccess(t('libresign', 'DPO configuration saved'))
			} catch (e) {
				showError(t('libresign', 'Failed to save DPO configuration'))
				console.error('DPO config save failed', e)
			}
		},
	},
}
</script>
