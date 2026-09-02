<template>
	<NcSettingsSection :name="name" :description="description">
		<div v-if="emailIdentifyMethodEnabled">
			<p>
				<NcCheckboxRadioSwitch type="switch" v-model="smsOtpEnabled"
					@update:modelValue="toggleSetting('sms_otp_enabled', smsOtpEnabled)">
					{{ t('libresign', 'Enable SMS OTP') }}
				</NcCheckboxRadioSwitch>
			</p>

			<div class="row">
				<NcPasswordField
					v-model="tiaraApiKey"
					:label="t('libresign', 'Tiara API Key')"
					:placeholder="tiaraApiKeySet
						? t('libresign', 'API key is already set')
						: t('libresign', 'Your Tiara API Key')"
					@update:modelValue="saveAppConfigValue('tiara_api_key', tiaraApiKey)" />
			</div>

			<div class="row">
				<NcTextField
					v-model="tiaraApiUrl"
					:label="t('libresign', 'Tiara API URL')"
					:placeholder="t('libresign', 'https://api.tiaraconnect.io/api/messaging/sendsms')"
					@update:modelValue="saveAppConfigValue('tiara_api_url', tiaraApiUrl)" />
			</div>

			<div class="row">
				<NcTextField
					v-model="tiaraSenderId"
					:label="t('libresign', 'Tiara Sender Id')"
					:placeholder="t('libresign', 'Your Tiara Sender ID')"
					@update:modelValue="saveAppConfigValue('tiara_sender_id', tiaraSenderId)" />
			</div>
		</div>
		<div v-else>
			<p>{{ t('libresign', 'SMS OTP settings are only available when the Email identification method is enabled.')
				}}</p>
		</div>
	</NcSettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'

export default {
	name: 'SmsOtpConfig',
	components: {
		NcSettingsSection,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcPasswordField,
	},
	data() {
		const identifyMethods = loadState('libresign', 'identify_methods', [])
		const emailIdentifyMethod = identifyMethods.find(
			method => method.name === 'email'
		)

		return {
			name: t('libresign', 'SMS Configuration'),
			description: t(
				'libresign',
				'Configure Tiara SMS API credentials for SMS authentication.'
			),

			emailIdentifyMethodEnabled:
				emailIdentifyMethod?.enabled ?? false,

			smsOtpEnabled: loadState(
				'libresign',
				'sms_otp_enabled',
				false
			),

			tiaraApiKey: '',
			tiaraApiKeySet: loadState(
				'libresign',
				'tiara_api_key_set',
				false
			),

			tiaraApiUrl: loadState(
				'libresign',
				'tiara_api_url',
				''
			),

			tiaraSenderId: loadState(
				'libresign',
				'tiara_sender_id',
				''
			),
		}
	},
	methods: {
		t,

		saveAppConfigValue(key, value) {
			OCP.AppConfig.setValue('libresign', key, value)
		},

		toggleSetting(setting, value) {
			OCP.AppConfig.setValue(
				'libresign',
				setting,
				value ? '1' : '0'
			)
		},
	},
}
</script>

<style scoped>
.row {
	margin-top: 16px;
}
</style>
