<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreSign contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="phone-exceptions">
		<NcNoteCard type="info">
			{{ t('libresign', 'Select the mobile network and payment provider to use when this exception is active.') }}
		</NcNoteCard>

		<div class="phone-exceptions__toolbar">
			<div>
				<h3 class="phone-exceptions__heading">
					{{ t('libresign', 'Configured exceptions') }}
				</h3>
				<p class="phone-exceptions__description">
					{{ t('libresign', 'Manage phone numbers with custom payment routing.') }}
				</p>
			</div>

			<NcButton
				variant="primary"
				@click="$emit('add')">
				{{ t('libresign', 'Add Phone exception') }}
			</NcButton>
		</div>

		<section
			v-if="overrides.length > 0"
			class="phone-exceptions__list"
			:aria-label="t('libresign', 'Configured phone exceptions')">
			<article
				v-for="item in overrides"
				:key="item.id"
				class="phone-exceptions__item">
				<div class="phone-exceptions__details">
					<span class="phone-exceptions__number">
						{{ item.phone }}
					</span>

					<span class="phone-exceptions__routing">
						{{ mnoLabel(item.mno) }} · {{ providerLabel(item.provider) }}
					</span>
				</div>

				<div class="phone-exceptions__status">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="item.active"
						:disabled="togglingId === item.id"
						@update:model-value="$emit('toggle', item)">
						{{ item.active
							? t('libresign', 'Active')
							: t('libresign', 'Inactive') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="phone-exceptions__actions">
					<NcButton
						variant="tertiary"
						:aria-label="t('libresign', 'Edit')"
						@click="$emit('edit', item)">
						<template #icon>
							<NcIconSvgWrapper
								:path="mdiPencil"
								:size="20" />
						</template>
					</NcButton>

					<template v-if="confirmDeleteId === item.id">
						<NcButton
							variant="error"
							:disabled="deletingId === item.id"
							@click="$emit('remove', item)">
							{{ t('libresign', 'Delete') }}
						</NcButton>

						<NcButton
							variant="tertiary"
							@click="$emit('cancel-delete')">
							{{ t('libresign', 'Cancel') }}
						</NcButton>
					</template>

					<NcButton
						v-else
						variant="tertiary"
						:aria-label="t('libresign', 'Delete')"
						@click="$emit('confirm-delete', item.id)">
						<template #icon>
							<NcIconSvgWrapper
								:path="mdiDelete"
								:size="20" />
						</template>
					</NcButton>
				</div>
			</article>
		</section>

		<NcEmptyContent
			v-else
			:name="t('libresign', 'No phone exceptions configured')"
			:description="t('libresign', 'Add a phone number to configure its payment routing.')" />
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { mdiDelete, mdiPencil } from '@mdi/js'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import type { PhoneOverride } from '@/composables/usePhoneOverrides'

interface Props {
	overrides: PhoneOverride[]
	togglingId: number | null
	confirmDeleteId: number | null
	deletingId: number | null
}

defineProps<Props>()

defineEmits<{
	add: []
	toggle: [item: PhoneOverride]
	edit: [item: PhoneOverride]
	remove: [item: PhoneOverride]
	'confirm-delete': [id: number]
	'cancel-delete': []
}>()

function mnoLabel(mno: string): string {
	return mno === 'safaricom'
		? t('libresign', 'Safaricom')
		: mno
}

function providerLabel(provider: string): string {
	if (provider === 'daraja') {
		return t('libresign', 'Daraja')
	}

	if (provider === 'dpo') {
		return t('libresign', 'DPO')
	}

	return provider
}
</script>

<style scoped lang="scss">
.phone-exceptions {
	display: flex;
	flex-direction: column;
	gap: 16px;
	width: 100%;
	box-sizing: border-box;
	padding: 32px 16px;

	&__toolbar {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		width: 100%;
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

	&__list {
		display: flex;
		flex-direction: column;
		width: 100%;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		overflow: hidden;
	}

	&__item {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto auto;
		gap: 16px;
		align-items: center;
		padding: 14px 16px;
		border-bottom: 1px solid var(--color-border);
		box-sizing: border-box;

		&:last-child {
			border-bottom: none;
		}
	}

	&__details {
		display: flex;
		flex-direction: column;
		gap: 3px;
		min-width: 0;
	}

	&__number {
		display: block;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-family: var(--font-face-monospace, monospace);
	}

	&__routing {
		display: block;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__status {
		display: flex;
		align-items: center;
		white-space: nowrap;
	}

	&__actions {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 4px;
		flex-wrap: wrap;
	}
}

@media (max-width: 700px) {
	.phone-exceptions {
		&__toolbar {
			flex-direction: column;
			align-items: stretch;
		}

		&__toolbar .button-vue {
			width: 100%;
		}

		&__item {
			grid-template-columns: minmax(0, 1fr) auto;
			gap: 10px 12px;
		}

		&__details {
			grid-column: 1;
		}

		&__status {
			grid-column: 2;
			grid-row: 1;
		}

		&__actions {
			grid-column: 1 / -1;
			justify-content: flex-end;
			padding-top: 4px;
		}
	}
}

@media (max-width: 480px) {
	.phone-exceptions {
		&__item {
			padding: 12px;
		}
	}
}
</style>
