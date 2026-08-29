/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Soft-collapse a row's height to zero before it's removed from the store,
 * so deletion reads as a gentle collapse rather than a pop-out.
 *
 * Pure DOM utility — no reactivity, no store access. Resolves when the
 * collapse transition ends (or after a safety timeout), and immediately
 * when the row isn't rendered inside the scroller.
 */
export function animateRowRemoval(scroller: HTMLElement | null | undefined, id: number | string): Promise<void> {
	return new Promise((resolve) => {
		const el = scroller?.querySelector(`[data-row-id="${CSS.escape(String(id))}"]`) as HTMLElement | null
		if (!el) {
			resolve()
			return
		}
		const startHeight = el.offsetHeight
		const cells = Array.from(el.querySelectorAll('td')) as HTMLElement[]
		el.style.height = `${startHeight}px`
		el.style.overflow = 'hidden'
		void el.offsetHeight // reflow to lock the starting height
		el.style.transition = 'height 0.28s ease, opacity 0.24s ease, padding 0.28s ease'
		cells.forEach((td) => { td.style.transition = 'padding 0.28s ease' })
		requestAnimationFrame(() => {
			el.style.height = '0'
			el.style.opacity = '0'
			el.style.paddingTop = '0'
			el.style.paddingBottom = '0'
			cells.forEach((td) => { td.style.paddingTop = '0'; td.style.paddingBottom = '0' })
		})
		let settled = false
		const finish = () => { if (!settled) { settled = true; resolve() } }
		el.addEventListener('transitionend', (e) => {
			if ((e as TransitionEvent).propertyName === 'height') { finish() }
		})
		window.setTimeout(finish, 360)
	})
}
