/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared status → My Documents deep-link navigation for the dashboard.
 * Both the desktop stat cards (StatsCards) and the mobile chips (StatsChips)
 * consume this, so the status mapping and the filter/navigation behaviour live
 * in exactly one place.
 */
import { useRouter } from 'vue-router'

import { FILE_STATUS } from '@/constants.js'
import { useFiltersStore } from '@/store/filters.js'

/**
 * Canonical dashboard filter targets, using the existing FILE_STATUS constants.
 * "Pending" = everything not Draft/Signed that the list filter can express
 * (SIGNING_IN_PROGRESS is also counted as pending by the backend but has no
 * filter option). Empty array = no status filter.
 */
export const DASHBOARD_STATUS = Object.freeze({
	all: [] as number[],
	pending: [FILE_STATUS.ABLE_TO_SIGN, FILE_STATUS.PARTIAL_SIGNED],
	completed: [FILE_STATUS.SIGNED],
	drafts: [FILE_STATUS.DRAFT],
})

export function useDashboardStatusNav() {
	const router = useRouter()
	const filtersStore = useFiltersStore()

	/**
	 * Apply the requested status filter to the shared store, then open the list.
	 * `emit: false` — the destination performs its own initial fetch, so we skip
	 * a redundant refetch here.
	 */
	function openDocuments(statuses: number[]) {
		filtersStore.applyStatusFilter(statuses, { emit: false })
		router.push({ name: 'fileslist' })
	}

	/** Native-button-like keyboard activation for role="button"/chip elements. */
	function onActivateKey(e: KeyboardEvent, statuses: number[]) {
		if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
			e.preventDefault()
			openDocuments(statuses)
		}
	}

	return {
		openDocuments,
		onActivateKey,
		DASHBOARD_STATUS
	}
}
