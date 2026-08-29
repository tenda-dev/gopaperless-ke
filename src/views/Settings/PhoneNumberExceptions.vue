<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Phone Number Exceptions')"
		:description="t('libresign', 'Use this when a phone number should be treated as a Safaricom number but automatic network detection cannot identify it correctly. Payments for active exceptions are routed through M-Pesa/Daraja.')">
		<p class="phone-exceptions__summary">
			{{ n('libresign', '%n exception configured.', '%n exceptions configured.', overrides.length) }}
		</p>
		<NcButton type="secondary" @click="openModal">
			{{ t('libresign', 'Manage phone exceptions') }}
		</NcButton>

		<NcDialog
			v-if="open"
			:open="open"
			:name="t('libresign', 'Phone Number Exceptions')"
			size="normal"
			@update:open="open = $event">
			<div class="phone-exceptions">
				<NcNoteCard type="info">
					{{ t('libresign', 'Every exception below is treated as Safaricom and routed through M-Pesa/Daraja.') }}
				</NcNoteCard>

				<div class="phone-exceptions__add">
					<NcTextField
						v-model="phone"
						type="tel"
						autocomplete="off"
						:label="t('libresign', 'Phone number')"
						:placeholder="t('libresign', '+2547XXXXXXXX')"
						:error="Boolean(error)"
						:helper-text="error"
						@keydown.enter="addException" />
					<p class="phone-exceptions__network">
						{{ t('libresign', 'Network') }}: <strong>{{ t('libresign', 'Safaricom') }}</strong>
						<span class="phone-exceptions__hint">{{ t('libresign', '(routed via M-Pesa/Daraja)') }}</span>
					</p>
					<NcButton
						type="primary"
						:disabled="submitting || phone.trim() === ''"
						@click="addException">
						{{ t('libresign', 'Add Safaricom exception') }}
					</NcButton>
				</div>

				<div v-if="overrides.length > 0" class="phone-exceptions__list">
					<div v-for="item in overrides" :key="item.id" class="phone-exceptions__row">
						<template v-if="editingId === item.id">
							<NcTextField
								v-model="editPhone"
								type="tel"
								class="phone-exceptions__edit-input"
								:label="t('libresign', 'Phone number')"
								:error="Boolean(editError)"
								:helper-text="editError"
								@keydown.enter="saveEdit(item)" />
							<NcButton type="primary" :disabled="savingId === item.id" @click="saveEdit(item)">
								{{ t('libresign', 'Save') }}
							</NcButton>
							<NcButton type="tertiary" @click="cancelEdit">
								{{ t('libresign', 'Cancel') }}
							</NcButton>
						</template>
						<template v-else>
							<div class="phone-exceptions__cell phone-exceptions__cell--phone">
								<span class="phone-exceptions__number">{{ item.phone }}</span>
								<span class="phone-exceptions__mno">{{ mnoLabel(item.mno) }}</span>
							</div>
							<NcCheckboxRadioSwitch
								type="switch"
								:model-value="item.active"
								:disabled="togglingId === item.id"
								@update:model-value="() => toggleActive(item)">
								{{ item.active ? t('libresign', 'Active') : t('libresign', 'Inactive') }}
							</NcCheckboxRadioSwitch>
							<NcButton
								type="tertiary"
								:aria-label="t('libresign', 'Edit')"
								@click="startEdit(item)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencil" :size="20" />
								</template>
							</NcButton>
							<template v-if="confirmDeleteId === item.id">
								<NcButton type="error" :disabled="deletingId === item.id" @click="remove(item)">
									{{ t('libresign', 'Confirm delete') }}
								</NcButton>
								<NcButton type="tertiary" @click="confirmDeleteId = null">
									{{ t('libresign', 'Cancel') }}
								</NcButton>
							</template>
							<NcButton
								v-else
								type="tertiary"
								:aria-label="t('libresign', 'Delete')"
								@click="confirmDeleteId = item.id">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="20" />
								</template>
							</NcButton>
						</template>
					</div>
				</div>
				<NcEmptyContent
					v-else
					:name="t('libresign', 'No phone exceptions configured')"
					:description="t('libresign', 'Add a number above to route it through M-Pesa/Daraja as Safaricom.')" />
			</div>
		</NcDialog>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { mdiPencil, mdiDelete } from '@mdi/js'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { showError, showSuccess } from '@/services/toast'

interface PhoneOverride {
	id: number
	phone: string
	mno: string
	active: boolean
	note: string | null
	createdBy: string | null
	createdAt: string | null
	updatedAt: string | null
}

const BASE_URL = '/apps/libresign/api/v1/admin/phone-overrides'

defineOptions({
	name: 'PhoneNumberExceptions',
})

const open = ref(false)
const overrides = ref<PhoneOverride[]>(
	loadState<PhoneOverride[]>('libresign', 'phone_overrides', []),
)
const phone = ref('')
const error = ref('')
const submitting = ref(false)
const togglingId = ref<number | null>(null)
const editingId = ref<number | null>(null)
const editPhone = ref('')
const editError = ref('')
const savingId = ref<number | null>(null)
const confirmDeleteId = ref<number | null>(null)
const deletingId = ref<number | null>(null)

function ocsError(e: unknown, fallback: string): string {
	const err = e as { response?: { data?: { ocs?: { data?: { error?: string } } } } }
	return err?.response?.data?.ocs?.data?.error ?? fallback
}

function statusOf(e: unknown): number | undefined {
	return (e as { response?: { status?: number } })?.response?.status
}

function mnoLabel(mno: string): string {
	return mno === 'safaricom' ? t('libresign', 'Safaricom') : mno
}

function idUrl(id: number): string {
	return generateOcsUrl(`${BASE_URL}/{id}`, { id })
}

async function refresh(): Promise<void> {
	try {
		const { data } = await axios.get(generateOcsUrl(BASE_URL))
		overrides.value = data?.ocs?.data?.overrides ?? []
	} catch (e) {
		console.error('Failed to load phone exceptions', e)
	}
}

function openModal(): void {
	open.value = true
	refresh()
}

async function addException(): Promise<void> {
	error.value = ''
	const value = phone.value.trim()
	if (value === '') {
		error.value = t('libresign', 'Enter a phone number.')
		return
	}

	submitting.value = true
	try {
		const { data } = await axios.post(generateOcsUrl(BASE_URL), { phone: value })
		const status = data?.ocs?.data?.status
		showSuccess(
			status === 'reactivated'
				? t('libresign', 'Phone exception reactivated')
				: t('libresign', 'Safaricom exception added'),
		)
		phone.value = ''
		await refresh()
	} catch (e) {
		if (statusOf(e) === 409) {
			showError(t('libresign', 'This phone number already has an exception configured.'))
		} else {
			const message = ocsError(e, t('libresign', 'Could not save the phone exception.'))
			error.value = message
			showError(message)
		}
	} finally {
		submitting.value = false
	}
}

function startEdit(item: PhoneOverride): void {
	editingId.value = item.id
	editPhone.value = item.phone
	editError.value = ''
	confirmDeleteId.value = null
}

function cancelEdit(): void {
	editingId.value = null
	editPhone.value = ''
	editError.value = ''
}

async function saveEdit(item: PhoneOverride): Promise<void> {
	editError.value = ''
	const value = editPhone.value.trim()
	if (value === '') {
		editError.value = t('libresign', 'Enter a phone number.')
		return
	}

	savingId.value = item.id
	try {
		await axios.patch(idUrl(item.id), { phone: value })
		showSuccess(t('libresign', 'Phone exception updated'))
		cancelEdit()
		await refresh()
	} catch (e) {
		if (statusOf(e) === 409) {
			editError.value = t('libresign', 'Another exception already uses this phone number.')
		} else {
			editError.value = ocsError(e, t('libresign', 'Could not update the phone exception.'))
		}
	} finally {
		savingId.value = null
	}
}

async function toggleActive(item: PhoneOverride): Promise<void> {
	togglingId.value = item.id
	const next = !item.active
	try {
		await axios.patch(idUrl(item.id), { active: next })
		item.active = next
		showSuccess(
			next
				? t('libresign', 'Phone exception activated')
				: t('libresign', 'Phone exception deactivated'),
		)
	} catch (e) {
		showError(ocsError(e, t('libresign', 'Could not update the phone exception.')))
	} finally {
		togglingId.value = null
	}
}

async function remove(item: PhoneOverride): Promise<void> {
	deletingId.value = item.id
	try {
		await axios.delete(idUrl(item.id))
		showSuccess(t('libresign', 'Phone exception deleted'))
		confirmDeleteId.value = null
		await refresh()
	} catch (e) {
		showError(ocsError(e, t('libresign', 'Could not delete the phone exception.')))
	} finally {
		deletingId.value = null
	}
}

defineExpose({
	open,
	overrides,
	phone,
	error,
	editingId,
	editPhone,
	editError,
	confirmDeleteId,
	openModal,
	addException,
	startEdit,
	cancelEdit,
	saveEdit,
	toggleActive,
	remove,
})
</script>

<style scoped lang="scss">
.phone-exceptions {
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__summary {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
	}

	&__add {
		display: flex;
		flex-direction: column;
		gap: 8px;
		align-items: flex-start;
	}

	&__network {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__list {
		display: flex;
		flex-direction: column;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		overflow: hidden;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);

		&:last-child {
			border-bottom: none;
		}
	}

	&__edit-input {
		flex: 1 1 auto;
	}

	&__cell--phone {
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__number {
		font-family: var(--font-face-monospace, monospace);
	}

	&__mno {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
