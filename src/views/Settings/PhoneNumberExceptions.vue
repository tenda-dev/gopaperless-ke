<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreSign contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Phone Number Exceptions')"
		:description="t('libresign', 'Configure payment routing for phone numbers that require an explicit mobile network or payment provider.')">
		<p class="phone-exceptions__summary">
			{{ n('libresign', '%n exception configured.', '%n exceptions configured.', overrides.length) }}
		</p>

		<NcButton variant="secondary" @click="openModal">
			{{ t('libresign', 'Manage phone exceptions') }}
		</NcButton>

		<NcDialog
			v-if="open"
			:open="open"
			:name="t('libresign', 'Phone Number Exceptions')"
			size="large"
			@update:open="handleDialogOpenChange">
			<div class="phone-exceptions">
				<NcNoteCard type="info">
					{{ t('libresign', 'Select the mobile network and payment provider to use when this exception is active.') }}
				</NcNoteCard>

				<!--
					List view
				-->
				<template v-if="view === 'list'">
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
							@click="showAddForm">
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
									@update:model-value="() => toggleActive(item)">
									{{ item.active
										? t('libresign', 'Active')
										: t('libresign', 'Inactive') }}
								</NcCheckboxRadioSwitch>
							</div>

							<div class="phone-exceptions__actions">
								<NcButton
									variant="tertiary"
									:aria-label="t('libresign', 'Edit')"
									@click="startEdit(item)">
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
										@click="remove(item)">
										{{ t('libresign', 'Delete') }}
									</NcButton>

									<NcButton
										variant="tertiary"
										@click="confirmDeleteId = null">
										{{ t('libresign', 'Cancel') }}
									</NcButton>
								</template>

								<NcButton
									v-else
									variant="tertiary"
									:aria-label="t('libresign', 'Delete')"
									@click="confirmDeleteId = item.id">
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
				</template>

				<!--
					Add / edit form
				-->
				<template v-else>
					<div class="phone-exceptions__form-header">
						<NcButton
							variant="tertiary"
							:aria-label="t('libresign', 'Back to phone exceptions')"
							:disabled="submitting || savingId !== null"
							@click="backToList">
							<template #icon>
								<NcIconSvgWrapper
									:path="mdiArrowLeft"
									:size="20" />
							</template>
						</NcButton>

						<div>
							<h3 class="phone-exceptions__heading">
								{{ view === 'add'
									? t('libresign', 'Add Phone exception')
									: t('libresign', 'Edit Phone exception') }}
							</h3>

							<p class="phone-exceptions__description">
								{{ view === 'add'
									? t('libresign', 'Configure payment routing for this phone number.')
									: t('libresign', 'Update the payment routing for this phone number.') }}
							</p>
						</div>
					</div>

					<form
						class="phone-exceptions__form"
						@submit.prevent="view === 'add' ? addException() : saveCurrentEdit()">
						<div class="phone-exceptions__field">
							<NcTextField
								v-if="view === 'add'"
								v-model="phone"
								type="tel"
								autocomplete="off"
								:label="t('libresign', 'Phone number')"
								:placeholder="t('libresign', '+2547XXXXXXXX')"
								:error="Boolean(error)"
								:helper-text="error" />

							<NcTextField
								v-else
								v-model="editPhone"
								type="tel"
								autocomplete="off"
								:label="t('libresign', 'Phone number')"
								:error="Boolean(editError)"
								:helper-text="editError" />
						</div>

						<div class="phone-exceptions__field">
							<NcSelect
								v-if="view === 'add'"
								v-model="selectedMno"
								:options="availableMnos"
								label="label"
								:input-label="t('libresign', 'Mobile network')"
								:clearable="false"
								:reduce="(option: OverrideOption) => option.value"
								:disabled="availableMnos.length === 0" />

							<NcSelect
								v-else
								v-model="editMno"
								:options="editAvailableMnos"
								label="label"
								:input-label="t('libresign', 'Mobile network')"
								:clearable="false"
								:reduce="(option: OverrideOption) => option.value"
								:disabled="editAvailableMnos.length === 0" />
						</div>

						<p
							v-if="view === 'add' && phone.trim() !== '' && !selectedRegion"
							class="phone-exceptions__hint">
							{{ t('libresign', 'Enter a valid international phone number to select a mobile network.') }}
						</p>

						<p
							v-if="view === 'edit' && editPhone.trim() !== '' && !editRegion"
							class="phone-exceptions__hint">
							{{ t('libresign', 'Enter a valid international phone number to select a mobile network.') }}
						</p>

						<div class="phone-exceptions__field">
							<NcSelect
								v-if="view === 'add'"
								v-model="selectedProvider"
								:options="overrideOptions.providers"
								label="label"
								:input-label="t('libresign', 'Payment provider')"
								:clearable="false"
								:reduce="(option: OverrideOption) => option.value"
								:disabled="overrideOptions.providers.length === 0" />

							<NcSelect
								v-else
								v-model="editProvider"
								:options="overrideOptions.providers"
								label="label"
								:input-label="t('libresign', 'Payment provider')"
								:clearable="false"
								:reduce="(option: OverrideOption) => option.value"
								:disabled="overrideOptions.providers.length === 0" />
						</div>

						<div class="phone-exceptions__form-actions">
							<NcButton
								variant="primary"
								type="submit"
								:disabled="view === 'add'
									? submitting || !canAdd
									: savingId !== null || !canSaveEdit">
								{{ view === 'add'
									? t('libresign', 'Add Phone exception')
									: t('libresign', 'Save') }}
							</NcButton>

							<NcButton
								variant="tertiary"
								type="button"
								:disabled="submitting || savingId !== null"
								@click="backToList">
								{{ t('libresign', 'Cancel') }}
							</NcButton>
						</div>
					</form>
				</template>
			</div>
		</NcDialog>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { mdiArrowLeft, mdiDelete, mdiPencil } from '@mdi/js'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { showError, showSuccess } from '@/services/toast'
import { resolvePhone } from '@/utils/phoneResolver'

interface PhoneOverride {
	id: number
	phone: string
	mno: string
	provider: string
	active: boolean
	note: string | null
	createdBy: string | null
	createdAt: string | null
	updatedAt: string | null
}

interface OverrideOption {
	value: string
	label: string
}

interface PhoneOverrideOptions {
	mnos: Record<string, OverrideOption[]>
	providers: OverrideOption[]
}

type View = 'list' | 'add' | 'edit'

const BASE_URL = '/apps/libresign/api/v1/admin/phone-overrides'

defineOptions({
	name: 'PhoneNumberExceptions',
})

const open = ref(false)
const view = ref<View>('list')

const overrides = ref<PhoneOverride[]>(
	loadState<PhoneOverride[]>('libresign', 'phone_overrides', []),
)

const overrideOptions = loadState<PhoneOverrideOptions>(
	'libresign',
	'phone_override_options',
	{
		mnos: {},
		providers: [],
	},
)

const phone = ref('')
const error = ref('')
const submitting = ref(false)

const selectedMno = ref('')
const selectedProvider = ref('daraja')
const selectedRegion = ref<string | null>(null)

const editingId = ref<number | null>(null)
const editPhone = ref('')
const editError = ref('')
const editMno = ref('')
const editProvider = ref('daraja')
const editRegion = ref<string | null>(null)
const savingId = ref<number | null>(null)

const togglingId = ref<number | null>(null)
const confirmDeleteId = ref<number | null>(null)
const deletingId = ref<number | null>(null)

const availableMnos = computed<OverrideOption[]>(() => {
	if (!selectedRegion.value) {
		return []
	}

	return overrideOptions.mnos[selectedRegion.value] ?? []
})

const editAvailableMnos = computed<OverrideOption[]>(() => {
	if (!editRegion.value) {
		return []
	}

	return overrideOptions.mnos[editRegion.value] ?? []
})

const canAdd = computed(() => {
	return Boolean(
		phone.value.trim()
		&& selectedRegion.value
		&& selectedMno.value
		&& selectedProvider.value,
	)
})

const canSaveEdit = computed(() => {
	return Boolean(
		editPhone.value.trim()
		&& editRegion.value
		&& editMno.value
		&& editProvider.value,
	)
})

watch(phone, updateRegion)
watch(editPhone, updateEditRegion)

function ocsError(e: unknown, fallback: string): string {
	const err = e as {
		response?: {
			data?: {
				ocs?: {
					data?: {
						error?: string
					}
				}
			}
		}
	}

	return err?.response?.data?.ocs?.data?.error ?? fallback
}

function statusOf(e: unknown): number | undefined {
	return (e as { response?: { status?: number } })?.response?.status
}

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

function idUrl(id: number): string {
	return generateOcsUrl(`${BASE_URL}/{id}`, { id })
}

function updateRegion(rawPhone: string): void {
	const resolved = resolvePhone(rawPhone)
	selectedRegion.value = resolved.region ?? null

	const options = selectedRegion.value
		? overrideOptions.mnos[selectedRegion.value] ?? []
		: []

	if (!options.some((option) => option.value === selectedMno.value)) {
		selectedMno.value = options[0]?.value ?? ''
	}
}

function updateEditRegion(rawPhone: string): void {
	const resolved = resolvePhone(rawPhone)
	editRegion.value = resolved.region ?? null

	const options = editRegion.value
		? overrideOptions.mnos[editRegion.value] ?? []
		: []

	if (!options.some((option) => option.value === editMno.value)) {
		editMno.value = options[0]?.value ?? ''
	}
}

function resetAddForm(): void {
	phone.value = ''
	error.value = ''
	selectedRegion.value = null
	selectedMno.value = ''

	const defaultProvider = overrideOptions.providers.find(
		(provider) => provider.value === 'daraja',
	)

	selectedProvider.value =
		defaultProvider?.value
		?? overrideOptions.providers[0]?.value
		?? 'daraja'
}

function resetEditForm(): void {
	editingId.value = null
	editPhone.value = ''
	editError.value = ''
	editMno.value = ''
	editProvider.value = 'daraja'
	editRegion.value = null
}

function showAddForm(): void {
	confirmDeleteId.value = null
	resetAddForm()
	view.value = 'add'
}

function startEdit(item: PhoneOverride): void {
	confirmDeleteId.value = null

	editingId.value = item.id
	editPhone.value = item.phone
	editMno.value = item.mno
	editProvider.value = item.provider
	editError.value = ''

	const resolved = resolvePhone(item.phone)
	editRegion.value = resolved.region ?? null

	const options = editRegion.value
		? overrideOptions.mnos[editRegion.value] ?? []
		: []

	if (!options.some((option) => option.value === editMno.value)) {
		editMno.value = options[0]?.value ?? ''
	}

	view.value = 'edit'
}

function backToList(): void {
	if (submitting.value || savingId.value !== null) {
		return
	}

	view.value = 'list'
	resetAddForm()
	resetEditForm()
	confirmDeleteId.value = null
}

function openModal(): void {
	open.value = true
	view.value = 'list'
	confirmDeleteId.value = null
	refresh()
}

function handleDialogOpenChange(value: boolean): void {
	open.value = value

	if (!value) {
		view.value = 'list'
		resetAddForm()
		resetEditForm()
		confirmDeleteId.value = null
	}
}

async function refresh(): Promise<void> {
	try {
		const { data } = await axios.get(generateOcsUrl(BASE_URL))
		overrides.value = data?.ocs?.data?.overrides ?? []
	} catch (e) {
		console.error('Failed to load phone exceptions', e)
	}
}

async function addException(): Promise<void> {
	error.value = ''

	const value = phone.value.trim()

	if (value === '') {
		error.value = t('libresign', 'Enter a phone number.')
		return
	}

	if (!selectedRegion.value || !selectedMno.value) {
		error.value = t(
			'libresign',
			'Enter a valid international phone number and select a mobile network.',
		)
		return
	}

	if (!selectedProvider.value) {
		error.value = t('libresign', 'Select a payment provider.')
		return
	}

	submitting.value = true

	try {
		const { data } = await axios.post(generateOcsUrl(BASE_URL), {
			phone: value,
			mno: selectedMno.value,
			provider: selectedProvider.value,
		})

		const status = data?.ocs?.data?.status

		showSuccess(
			status === 'reactivated'
				? t('libresign', 'Phone exception reactivated')
				: t('libresign', 'Phone exception added'),
		)

		resetAddForm()
		await refresh()

		// Always return to the list after a successful add.
		view.value = 'list'
	} catch (e) {
		if (statusOf(e) === 409) {
			showError(
				t(
					'libresign',
					'This phone number already has an exception configured.',
				),
			)
		} else {
			const message = ocsError(
				e,
				t('libresign', 'Could not save the phone exception.'),
			)

			error.value = message
			showError(message)
		}
	} finally {
		submitting.value = false
	}
}

async function saveCurrentEdit(): Promise<void> {
	if (editingId.value === null) {
		return
	}

	const item = overrides.value.find(
		(override) => override.id === editingId.value,
	)

	if (!item) {
		return
	}

	await saveEdit(item)
}

async function saveEdit(item: PhoneOverride): Promise<void> {
	editError.value = ''

	const value = editPhone.value.trim()

	if (value === '') {
		editError.value = t('libresign', 'Enter a phone number.')
		return
	}

	if (!editRegion.value || !editMno.value) {
		editError.value = t(
			'libresign',
			'Enter a valid international phone number and select a mobile network.',
		)
		return
	}

	if (!editProvider.value) {
		editError.value = t('libresign', 'Select a payment provider.')
		return
	}

	savingId.value = item.id

	try {
		console.log('Saving phone exception', item.id, value, editMno.value, editProvider.value)
		await axios.patch(idUrl(item.id), {
			phone: value,
			mno: editMno.value,
			provider: editProvider.value,
		})

		showSuccess(t('libresign', 'Phone exception updated'))

		resetEditForm()
		await refresh()

		// Always return to the list after a successful edit.
		view.value = 'list'
	} catch (e) {
		if (statusOf(e) === 409) {
			editError.value = t(
				'libresign',
				'Another exception already uses this phone number.',
			)
		} else {
			editError.value = ocsError(
				e,
				t('libresign', 'Could not update the phone exception.'),
			)
		}
	} finally {
		savingId.value = null
	}
}

async function toggleActive(item: PhoneOverride): Promise<void> {
	togglingId.value = item.id

	const next = !item.active

	try {
		await axios.patch(idUrl(item.id), {
			active: next,
		})

		item.active = next

		showSuccess(
			next
				? t('libresign', 'Phone exception activated')
				: t('libresign', 'Phone exception deactivated'),
		)
	} catch (e) {
		showError(
			ocsError(
				e,
				t('libresign', 'Could not update the phone exception.'),
			),
		)
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
		showError(
			ocsError(
				e,
				t('libresign', 'Could not delete the phone exception.'),
			),
		)
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
	backToList,
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
	width: 100%;
	box-sizing: border-box;
	padding: 32px 16px;

	&__summary {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
	}

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

	&__form-header {
		display: flex;
		align-items: center;
		gap: 8px;
		width: 100%;
	}

	&__form-header > div {
		min-width: 0;
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

	&__form-actions {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 4px;
	}

	:deep(.checkbox-radio-switch) {
		flex-shrink: 0;
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

		&__form-actions {
			flex-direction: column-reverse;
			align-items: stretch;

			:deep(button) {
				width: 100%;
			}
		}
	}
}
</style>
