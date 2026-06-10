<template>
	<NcSettingsSection :name="name" :description="description">

		<div class="row">
			<NcSelect
				v-model="selectedDispatcher"
				:options="dispatcherOptions"
				label="label"
				track-by="id"
				:input-label="t('libresign', 'Verification Dispatcher')"
				@update:model-value="saveDispatcher" />
		</div>

	</NcSettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'PaymentVerificationDispatcherConfig',

	components: {
		NcSettingsSection,
		NcSelect,
	},

	data() {
		const dispatcher = loadState(
			'libresign',
			'payment_verification_dispatcher',
			'nextcloud',
		)

		const dispatcherOptions = [
			{
				id: 'nextcloud',
				label: t(
					'libresign',
					'Nextcloud Background Jobs'
				),
			},
			{
				id: 'rabbitmq',
				label: t(
					'libresign',
					'RabbitMQ'
				),
			},
		]

		return {
			name: t(
				'libresign',
				'Payment Verification Queue'
			),

			description: t(
				'libresign',
				'Choose how payment verification jobs are scheduled.'
			),

			dispatcherOptions,

			selectedDispatcher:
				dispatcherOptions.find(
					option => option.id === dispatcher,
				) ?? dispatcherOptions[0],
		}
	},

	methods: {
		t,

		saveDispatcher(option) {
			console.log('Selected dispatcher:', option)
			if (!option) {
				return
			}

			OCP.AppConfig.setValue(
				'libresign',
				'payment_verification_dispatcher',
				option.id,
			)
		},
	},
	mounted() {
		console.log('selected dispatcher', this.selectedDispatcher)
	}
}
</script>

<style scoped>
.row {
	margin-bottom: 16px;
	max-width: 500px;
}
</style>
