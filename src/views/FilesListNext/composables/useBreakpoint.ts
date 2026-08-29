/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Tracks whether the list is at the mobile breakpoint. Uses a local matchMedia
 * (768px) to match the legacy list's mobile CSS breakpoint, rather than the
 * wider Nextcloud nav breakpoint.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const MOBILE_QUERY = '(max-width: 768px)'

/**
 * @param onChange optional callback fired whenever the breakpoint flips, with
 *   the new `isMobile` value (used e.g. to leave mobile select mode on resize).
 */
export function useBreakpoint(onChange?: (isMobile: boolean) => void) {
	const isMobile = ref(false)
	let mql: MediaQueryList | null = null

	const apply = (e: MediaQueryList | MediaQueryListEvent) => {
		isMobile.value = e.matches
		onChange?.(isMobile.value)
	}

	onMounted(() => {
		mql = window.matchMedia(MOBILE_QUERY)
		apply(mql)
		mql.addEventListener('change', apply)
	})

	onBeforeUnmount(() => {
		mql?.removeEventListener('change', apply)
	})

	return { isMobile }
}
