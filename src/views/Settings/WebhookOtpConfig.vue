<template>
	<NcSettingsSection :name="name" :description="description">
		<div v-if="emailIdentifyMethodEnabled">
			<p>
				<NcCheckboxRadioSwitch
					type="switch"
					:checked.sync="webhookOtpEnabled"
					@update:checked="
						toggleSetting(
							'webhook_otp_enabled',
							webhookOtpEnabled
						)
					">
					{{ t('libresign', 'Enable Webhook OTP') }}
				</NcCheckboxRadioSwitch>
			</p>

			<div class="row">
				<NcTextField
					v-model="webhookOtpUrl"
					:label="t('libresign', 'Webhook URL')"
					:placeholder="
						t(
							'libresign',
							'https://gopaperless.astralyngroup.com/webhooks/gopaperless/email-tokens'
						)
					"
					@update:modelValue="
						saveAppConfigValue(
							'webhook_otp_url',
							webhookOtpUrl
						)
					" />
			</div>

			<div class="row">
				<NcPasswordField
					v-model="webhookOtpSharedSecret"
					:label="
						t(
							'libresign',
							'Webhook Shared Secret'
						)
					"
					:placeholder="
						t(
							'libresign',
							'Webhook shared secret'
						)
					"
					@update:modelValue="
						saveAppConfigValue(
							'webhook_otp_shared_secret',
							webhookOtpSharedSecret
						)
					" />
			</div>
		</div>

		<div v-else>
			<p>
				{{
					t(
						'libresign',
						'Webhook OTP settings are only available when the Email identification method is enabled.'
					)
				}}
			</p>
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
	name: 'WebhookOtpConfig',

	components: {
		NcSettingsSection,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcPasswordField,
	},

	data() {
		const identifyMethods = loadState(
			'libresign',
			'identify_methods',
			[]
		)

		const emailIdentifyMethod = identifyMethods.find(
			method => method.name === 'email'
		)

		return {
			name: t(
				'libresign',
				'Webhook OTP Configuration'
			),

			description: t(
				'libresign',
				'Configure webhook settings for external OTP delivery integrations.'
			),

			emailIdentifyMethodEnabled:
				emailIdentifyMethod?.enabled ?? false,

			webhookOtpEnabled: loadState(
				'libresign',
				'webhook_otp_enabled',
				false
			),

			webhookOtpUrl: loadState(
				'libresign',
				'webhook_otp_url',
				''
			),

			webhookOtpSharedSecret: loadState(
				'libresign',
				'webhook_otp_shared_secret',
				''
			),
		}
	},

	methods: {
		t,

		saveAppConfigValue(key, value) {
			OCP.AppConfig.setValue(
				'libresign',
				key,
				value
			)
		},

		toggleSetting(setting, value) {
			OCP.AppConfig.setValue(
				'libresign',
				setting,
				value
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
