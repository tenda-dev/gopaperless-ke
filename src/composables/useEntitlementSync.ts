/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onScopeDispose, watch } from 'vue'
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core'
import { entitlementCoordinator } from '@/payment/entitlementCoordinator'
import type { ProductCode } from '@/constants/product'

const DEFAULT_INTERVAL_MS = 30_000 // Polling interval

/**
 * Controls entitlement synchronization behaviour for individual surfaces.
 *
 * Polling can be disabled where entitlement changes are driven by the surface's
 * own actions, while visibility-based and coordinator-driven refreshes remain
 * available. This avoids redundant background requests without changing the
 * default behaviour of other consumers.
 */
export interface EntitlementSyncOptions {
	/**
	 * Enable periodic entitlement refreshes. Defaults to true.
	 */
	poll?: boolean
	/**
	 * Polling cadence when periodic refreshes are enabled. Defaults to 30 seconds.
	 */
	intervalMs?: number
}

/**
 * Synchronizes entitlement state while respecting document visibility.
 *
 * Refresh coordination is delegated to entitlementCoordinator so mounted
 * surfaces share its freshness, suppression, and backoff policies.
 */
export function useEntitlementSync(
	productCode: ProductCode = 'SIGN_DOCUMENT',
	options: EntitlementSyncOptions = {},
): void {
	const { poll = true, intervalMs = DEFAULT_INTERVAL_MS } = options

	const visibility = useDocumentVisibility()

	const interval = poll
		? useIntervalFn(
			() => {
				// Let the coordinator decide whether another request is warranted based on
				// entitlement freshness and the current backoff state.
				entitlementCoordinator.refreshIfStale(productCode)
			},
			intervalMs,
		)
		: null

	const stopVisibilityWatch = watch(visibility, (value) => {
		if (value === 'visible') {
			// Revalidate when the user returns because entitlement state may have changed
			// while the surface was inactive. This remains the freshness mechanism for
			// surfaces that have opted out of periodic polling.
			entitlementCoordinator.refreshNow(productCode)
			interval?.resume()
		} else {
			interval?.pause()
		}
	})

	onScopeDispose(() => {
		interval?.pause()
		stopVisibilityWatch()
	})
}
