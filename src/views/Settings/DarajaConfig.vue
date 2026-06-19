<template>
	<NcSettingsSection :name="name" :description="description">
		<div class="row">
			<NcTextArea v-model="baseUrl" :label="t('libresign', 'Daraja Base URL')"
				:placeholder="t('libresign', 'https://api.daraja.example.com')"
				@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="consumerKey" :label="t('libresign', 'Consumer Key')"
				:placeholder="t('libresign', 'Your Daraja Consumer Key')"
				@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="consumerSecret" :label="t('libresign', 'Consumer Secret')" type="password"
				:placeholder="consumerSecretSet ? t('libresign', '••••••••') : t('libresign', 'Your Daraja Consumer Secret')"
				@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="shortCode" :label="t('libresign', 'Shortcode')" :placeholder="t('libresign', '174379')"
				@change="saveConfig" />
		</div>

		<div class="row">
			<NcTextArea v-model="passKey" :label="t('libresign', 'Pass Key')" type="password"
				:placeholder="passKeySet ? t('libresign', '••••••••') : t('libresign', 'Your Daraja Pass Key')"
				@change="saveConfig" />
		</div>
		<div class="row">
			<NcTextArea v-model="goPaperlessCallbackUrl" :label="t('libresign', 'GoPaperless Callback Base Url (shared with DPO)')"
				type="text" :placeholder="t('libresign', 'https://your-domain.example.com/webhooks/gopaperless')"
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
	name: 'DarajaConfig',
	components: {
		NcSettingsSection,
		NcTextArea,
	},
	data() {
		return {
			name: t('libresign', 'Daraja Configuration'),
			description: t('libresign', 'Configure Safaricom Daraja API credentials.'),

			baseUrl: loadState('libresign', 'daraja_base_url', ''),
			consumerKey: loadState('libresign', 'daraja_consumer_key', ''),
			consumerSecretSet: loadState('libresign', 'daraja_consumer_secret_set', false),
			consumerSecret: '',
			shortCode: loadState('libresign', 'daraja_shortcode', ''),
			passKeySet: loadState('libresign', 'daraja_pass_key_set', false),
			passKey: '',
			goPaperlessCallbackUrl: loadState('libresign', 'gopaperless_callback_base_url', ''),
		}
	},
	methods: {
		t,
		async saveConfig() {
			try {
				const response = await axios.post(
					generateOcsUrl('/apps/libresign/api/v1/admin/daraja-config'),
					{
						baseUrl: this.baseUrl,
						consumerKey: this.consumerKey,
						consumerSecret: this.consumerSecret,
						passKey: this.passKey,
						shortCode: this.shortCode,
						callbackBaseUrl: this.goPaperlessCallbackUrl,
					}
				)

				if (response.data.ocs?.data?.error) {
					showError(t('libresign', 'Failed to save Daraja configuration: {error}', {
						error: response.data.ocs.data.error,
					}))
					return
				}

				showSuccess(t('libresign', 'Daraja configuration saved'))
			} catch (e) {
				showError(t('libresign', 'Failed to save Daraja configuration'))
				console.error('Daraja config save failed', e)
			}
		},
	},
}
</script>
