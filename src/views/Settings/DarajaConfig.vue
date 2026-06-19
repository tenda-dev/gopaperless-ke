<template>
	<NcSettingsSection :name="name" :description="description">
		<div class="row">
			<NcTextArea v-model="baseUrl" :label="t('libresign', 'Daraja Base URL')"
				:placeholder="t('libresign', 'https://api.daraja.example.com')"
				@input="saveAppConfigValue('daraja_base_url', baseUrl)" />
		</div>

		<div class="row">
			<NcTextArea v-model="consumerKey" :label="t('libresign', 'Consumer Key')"
				:placeholder="t('libresign', 'Your Daraja Consumer Key')"
				@input="saveAppConfigValue('daraja_consumer_key', consumerKey)" />
		</div>

		<div class="row">
			<NcTextArea v-model="consumerSecret" :label="t('libresign', 'Consumer Secret')" type="password"
				:placeholder="consumerSecretSet ? t('libresign', '••••••••') : t('libresign', 'Your Daraja Consumer Secret')"
				@input="saveAppConfigValue('daraja_consumer_secret', consumerSecret)" />
		</div>

		<div class="row">
			<NcTextArea v-model="shortCode" :label="t('libresign', 'Shortcode')" :placeholder="t('libresign', '174379')"
				@input="saveAppConfigValue('daraja_shortcode', shortCode)" />
		</div>

		<div class="row">
			<NcTextArea v-model="passKey" :label="t('libresign', 'Pass Key')" type="password"
				:placeholder="passKeySet ? t('libresign', '••••••••') : t('libresign', 'Your Daraja Pass Key')"
				@input="saveAppConfigValue('daraja_pass_key', passKey)" />
		</div>
		<div class="row">
			<NcTextArea v-model="goPaperlessCallbackUrl" :label="t('libresign', 'GoPaperless Callback Base Url (shared with DPO)')"
				type="text" :placeholder="t('libresign', 'https://your-domain.example.com/webhooks/gopaperless')"
				@input="saveAppConfigValue('gopaperless_callback_base_url', goPaperlessCallbackUrl)" />
		</div>

	</NcSettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

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
		saveAppConfigValue(key, value) {
			if (!value) {
				return
			}
			OCP.AppConfig.setValue('libresign', key, value)
		},
	},
}
</script>
