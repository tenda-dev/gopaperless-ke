/**
 * SPDX-FileCopyrightText: 2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import logger from '../helpers/logger'
import { getTimePresetRange } from '../utils/timePresets.js'
import { getStatusLabel } from '../utils/fileStatus.js'

/**
 * @typedef {{ id?: string, [key: string]: unknown }} FilterChip
 */

export const useFiltersStore = defineStore('filter', () => {
	const initialFilters = loadState('libresign', 'filters', {})
	const chips = ref(/** @type {Record<string, FilterChip[]>} */ ({}))
	const filter_modified = ref(initialFilters.files_list_filter_modified ?? '')
	const filter_status = ref(initialFilters.files_list_filter_status ?? '')

	const activeChips = computed(() => Object.values(chips.value).flat())

	const filterStatusArray = computed(() => {
		try {
			return filter_status.value !== '' ? JSON.parse(filter_status.value) : []
		} catch (e) {
			return []
		}
	})

	/**
	 * Returns { start, end } in ms for the saved modified preset, or null.
	 * Computed fresh on each access so date boundaries are always current.
	 */
	const filterModifiedRange = computed(() => getTimePresetRange(filter_modified.value))

	const onFilterUpdateChips = async (event) => {
		chips.value = { ...chips.value, [event.id]: [...event.detail] }
		logger.debug('File list filter chips updated', { chips: event.detail })
	}

	const onFilterUpdateChipsAndSave = async (event) => {
		chips.value = { ...chips.value, [event.id]: [...event.detail] }

		if (event.id === 'modified') {
			const value = chips.value.modified?.[0]?.id || ''
			await axios.put(generateOcsUrl('/apps/libresign/api/v1/account/config/{key}', { key: 'files_list_filter_modified' }), {
				value,
			})
			filter_modified.value = value
			emit('libresign:filters:update')
		}

		if (event.id === 'status') {
			const value = event.detail.length > 0 ? JSON.stringify(event.detail.map(item => item.id)) : ''
			await axios.put(generateOcsUrl('/apps/libresign/api/v1/account/config/{key}', { key: 'files_list_filter_status' }), {
				value,
			})
			filter_status.value = value
			emit('libresign:filters:update')
		}

		logger.debug('File list filter chips updated', { chips: event.detail })
	}

	/**
	 * Applies a status filter from any FilesListNext entry point, including
	 * dashboard stat-card navigation. Updates the in-memory filter and chips
	 * immediately, then optionally persists the selection and notifies mounted
	 * file lists to refetch.
	 *
	 * `emit: false` is used when navigation will mount a fresh FilesListNext,
	 * avoiding a redundant refetch before the navigation completes.
	 *
	 * @param {number[]} ids canonical FILE_STATUS ids; an empty array clears the filter
	 * @param {{ persist?: boolean, emit?: boolean }} [opts]
	 *   persist — persist the selection to account config (default true)
	 *   emit — notify mounted file lists to refetch (default true)
	 */
	const applyStatusFilter = async (ids, opts = {}) => {
		const { persist = true, emit: shouldEmit = true } = opts
		const value = ids.length > 0 ? JSON.stringify(ids) : ''

		// Rebuild the chips from the canonical ids so externally applied filters
		// are immediately reflected in the existing filter-chip UI.
		chips.value = {
			...chips.value,
			status: ids.map((id) => ({
				id,
				text: getStatusLabel(id),
				onclick: () => applyStatusFilter(ids.filter((s) => s !== id)),
			})),
		}
		filter_status.value = value

		if (shouldEmit) {
			emit('libresign:filters:update')
		}

		if (persist) {
			try {
				await axios.put(generateOcsUrl('/apps/libresign/api/v1/account/config/{key}', { key: 'files_list_filter_status' }), { value })
			} catch (e) {
				logger.error('Failed to persist status filter', { error: e })
			}
		}
	}

	/**
	 * Clear all list filters (status + modified) and their chips. Called when the
	 * FilesListNext view unmounts so filter state never leaks into the next visit
	 * (e.g. opening the list from the sidebar after a dashboard stat-card deep
	 * link). Persists the cleared state so it survives a reload too. Does NOT emit
	 * `libresign:filters:update` — the view is going away, nothing should refetch.
	 */
	const resetFilters = async () => {
		chips.value = {}
		filter_status.value = ''
		filter_modified.value = ''
		try {
			await Promise.all([
				axios.put(generateOcsUrl('/apps/libresign/api/v1/account/config/{key}', { key: 'files_list_filter_status' }), { value: '' }),
				axios.put(generateOcsUrl('/apps/libresign/api/v1/account/config/{key}', { key: 'files_list_filter_modified' }), { value: '' }),
			])
		} catch (e) {
			logger.error('Failed to persist filter reset', { error: e })
		}
	}

	return {
		chips,
		filter_modified,
		filter_status,
		activeChips,
		filterStatusArray,
		filterModifiedRange,
		onFilterUpdateChips,
		onFilterUpdateChipsAndSave,
		applyStatusFilter,
		resetFilters,
	}
})
