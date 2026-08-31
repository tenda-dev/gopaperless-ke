<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreSign contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="phone-exception-form">
		<div class="phone-exception-form__header">
			<NcButton
				variant="tertiary"
				:aria-label="t('libresign', 'Back to phone exceptions')"
				:disabled="disabled"
				@click="$emit('back')">
				<template #icon>
					<NcIconSvgWrapper
						:path="mdiArrowLeft"
						:size="20" />
				</template>
			</NcButton>

			<div>
				<h3 class="phone-exception-form__heading">
					{{ mode === 'add'
						? t('libresign', 'Add Phone exception')
						: t('libresign', 'Edit Phone exception') }}
				</h3>

				<p class="phone-exception-form__description">
					{{ mode === 'add'
						? t('libresign', 'Configure payment routing for this phone number.')
						: t('libresign', 'Update the payment routing for this phone number.') }}
				</p>
			</div>
		</div>

		<form
			class="phone-exception-form__form"
			@submit.prevent="$emit('submit')">
			<div class="phone-exception-form__field">
				<NcTextField
					:model-value="phone"
					type="tel"
					autocomplete="off"
					:label="t('libresign', 'Phone number')"
					:placeholder="t('libresign', '+2547XXXXXXXX')"
					:error="Boolean(error)"
					:helper-text="error"
					@update:model-value="$emit('update:phone', String($event))" />
			</div>

			<div class="phone-exception-form__field">
				<NcSelect
					:model-value="mno"
					:options="availableMnos"
					label="label"
					:input-label="t('libresign', 'Mobile network')"
					:clearable="false"
					:reduce="(option: OverrideOption) => option.value"
					:disabled="availableMnos.length === 0"
					@update:model-value="$emit('update:mno', String($event))" />
			</div>

			<p
				v-if="phone.trim() !== '' && !region"
				class="phone-exception-form__hint">
				{{ t('libresign', 'Enter a valid international phone number to select a mobile network.') }}
			</p>

			<div class="phone-exception-form__field">
				<NcSelect
					:model-value="provider"
					:options="providers"
					label="label"
					:input-label="t('libresign', 'Payment provider')"
					:clearable="false"
					:reduce="(option: OverrideOption) => option.value"
					:disabled="providers.length === 0"
					@update:model-value="$emit('update:provider', String($event))" />
			</div>

			<div class="phone-exception-form__actions">
				<NcButton
					variant="primary"
					type="submit"
					:disabled="disabled || !canSubmit">
					{{ mode === 'add'
						? t('libresign', 'Add Phone exception')
						: t('libresign', 'Save') }}
				</NcButton>

				<NcButton
					variant="tertiary"
					type="button"
					:disabled="disabled"
					@click="$emit('back')">
					{{ t('libresign', 'Cancel') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { mdiArrowLeft } from '@mdi/js'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import type { OverrideOption } from '@/composables/usePhoneOverrides'

interface Props {
	mode: 'add' | 'edit'
	phone: string
	error: string
	mno: string
	provider: string
	region: string | null
	availableMnos: OverrideOption[]
	providers: OverrideOption[]
	submitting: boolean
	saving: boolean
}

const props = defineProps<Props>()

defineEmits<{
	'update:phone': [value: string]
	'update:mno': [value: string]
	'update:provider': [value: string]
	submit: []
	back: []
}>()

const disabled = computed(() => props.submitting || props.saving)

const canSubmit = computed(() => {
	return Boolean(
		props.phone.trim()
		&& props.region
		&& props.mno
		&& props.provider,
	)
})
</script>

<style scoped lang="scss">
.phone-exception-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	width: 100%;
	box-sizing: border-box;
	padding: 32px 16px;

	&__header {
		display: flex;
		align-items: center;
		gap: 8px;
		width: 100%;

		> div {
			min-width: 0;
		}
	}

	&__heading {
		margin: 0;
		font-size: 1.1rem;
		font-weight: 600;
	}

	&__description {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 16px;
		width: 100%;
	}

	&__field {
		width: 100%;
		min-width: 0;
	}

	&__hint {
		margin: -8px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__actions {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 4px;
	}
}

@media (max-width: 480px) {
	.phone-exception-form {
		&__actions {
			flex-direction: column-reverse;
			align-items: stretch;

			:deep(button) {
				width: 100%;
			}
		}
	}
}
</style>
