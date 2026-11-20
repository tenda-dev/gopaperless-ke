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
 * Visibility-aware entitlement synchronization.
 *
 * This composable delegates refresh coordination to entitlementCoordinator
 * rather than managing store state directly, ensuring mounted surfaces share
 * the same freshness, suppression, and backoff policies.
 */
export function useEntitlementSync(
	productCode: ProductCode = 'SIGN_DOCUMENT',
	intervalMs: number = DEFAULT_INTERVAL_MS,
): void {
	const visibility = useDocumentVisibility()

	const { pause, resume } = useIntervalFn(
		() => {
			// Refresh only when the coordinator's freshness and backoff policies allow it.
			entitlementCoordinator.refreshIfStale(productCode)
		},
		intervalMs,
	)

	const stopVisibilityWatch = watch(visibility, (value) => {
		if (value === 'visible') {
			// Refresh immediately when visibility returns because the polling interval
			// may have elapsed while the tab was inactive.
			entitlementCoordinator.refreshNow(productCode)
			resume()
		} else {
			pause()
		}
	})

	onScopeDispose(() => {
		pause()
		stopVisibilityWatch()
	})
}
