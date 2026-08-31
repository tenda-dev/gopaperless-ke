/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import { showError, showSuccess } from '@/services/toast'
import { resolvePhone } from '@/utils/phoneResolver'
import type { SupportedRegion } from '@/utils/phoneResolver'

export interface PhoneOverride {
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

export interface OverrideOption {
	value: string
	label: string
}

export interface PhoneOverrideOptions {
	mnos: Record<string, OverrideOption[]>
	providers: OverrideOption[]
}

export type OverrideView = 'list' | 'add' | 'edit'

const BASE_URL = '/apps/libresign/api/v1/admin/phone-overrides'

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

function firstProvider(options: PhoneOverrideOptions): string {
	return options.providers[0]?.value ?? ''
}

export function usePhoneOverrides(defaultRegion: SupportedRegion = 'KE') {
	const open = ref(false)
	const view = ref<OverrideView>('list')

	const overrides = ref<PhoneOverride[]>(
		loadState<PhoneOverride[]>('libresign', 'phone_overrides', []),
	)

	const overrideOptions = loadState<PhoneOverrideOptions>(
		'libresign',
		'phone_override_options',
		{ mnos: {}, providers: [] },
	)

	const phone = ref('')
	const error = ref('')
	const submitting = ref(false)

	const selectedMno = ref('')
	const selectedProvider = ref(firstProvider(overrideOptions))
	const selectedRegion = ref<string | null>(null)

	const editingId = ref<number | null>(null)
	const editPhone = ref('')
	const editError = ref('')
	const editMno = ref('')
	const editProvider = ref(firstProvider(overrideOptions))
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

	watch(phone, (rawPhone) => {
		const resolved = resolvePhone(rawPhone, defaultRegion)
		selectedRegion.value = resolved.region ?? null

		const options = selectedRegion.value
			? overrideOptions.mnos[selectedRegion.value] ?? []
			: []

		if (!options.some((option) => option.value === selectedMno.value)) {
			selectedMno.value = options[0]?.value ?? ''
		}
	})

	watch(editPhone, (rawPhone) => {
		const resolved = resolvePhone(rawPhone, defaultRegion)
		editRegion.value = resolved.region ?? null

		const options = editRegion.value
			? overrideOptions.mnos[editRegion.value] ?? []
			: []

		if (!options.some((option) => option.value === editMno.value)) {
			editMno.value = options[0]?.value ?? ''
		}
	})

	function resetAddForm(): void {
		phone.value = ''
		error.value = ''
		selectedRegion.value = null
		selectedMno.value = ''
		selectedProvider.value = firstProvider(overrideOptions)
	}

	function resetEditForm(): void {
		editingId.value = null
		editPhone.value = ''
		editError.value = ''
		editMno.value = ''
		editProvider.value = firstProvider(overrideOptions)
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

		const resolved = resolvePhone(item.phone, defaultRegion)
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
		void refresh()
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
			await axios.patch(generateOcsUrl(`${BASE_URL}/{id}`, { id: item.id }), {
				phone: value,
				mno: editMno.value,
				provider: editProvider.value,
			})

			showSuccess(t('libresign', 'Phone exception updated'))

			resetEditForm()
			await refresh()
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
			await axios.patch(generateOcsUrl(`${BASE_URL}/{id}`, { id: item.id }), {
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
			await axios.delete(generateOcsUrl(`${BASE_URL}/{id}`, { id: item.id }))

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

	return {
		open,
		view,
		overrides,
		overrideOptions,
		phone,
		error,
		submitting,
		selectedMno,
		selectedProvider,
		selectedRegion,
		editingId,
		editPhone,
		editError,
		editMno,
		editProvider,
		editRegion,
		savingId,
		togglingId,
		confirmDeleteId,
		deletingId,
		availableMnos,
		editAvailableMnos,
		canAdd,
		canSaveEdit,
		showAddForm,
		startEdit,
		backToList,
		openModal,
		handleDialogOpenChange,
		refresh,
		addException,
		saveEdit,
		toggleActive,
		remove,
	}
}
