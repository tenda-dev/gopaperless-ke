/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { animateRowRemoval } from '../../../views/FilesListNext/utils/animateRowRemoval.ts'

// happy-dom may not provide the CSS global — the utility only needs escape().
if (typeof globalThis.CSS === 'undefined') {
	vi.stubGlobal('CSS', { escape: (value: string) => value })
}

describe('animateRowRemoval', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('resolves immediately when the scroller is missing', async () => {
		await expect(animateRowRemoval(null, 1)).resolves.toBeUndefined()
		await expect(animateRowRemoval(undefined, 1)).resolves.toBeUndefined()
	})

	it('resolves immediately when the row is not rendered', async () => {
		const scroller = document.createElement('div')

		await expect(animateRowRemoval(scroller, 42)).resolves.toBeUndefined()
	})

	it('collapses the row and resolves after the safety timeout', async () => {
		const scroller = document.createElement('div')
		const row = document.createElement('tr')
		row.setAttribute('data-row-id', '7')
		scroller.appendChild(row)
		document.body.appendChild(scroller)

		let resolved = false
		const promise = animateRowRemoval(scroller, 7).then(() => { resolved = true })

		// Collapse styles are applied on the next animation frame.
		await vi.advanceTimersByTimeAsync(50)
		expect(row.style.overflow).toBe('hidden')

		await vi.advanceTimersByTimeAsync(400)
		await promise

		expect(resolved).toBe(true)
		scroller.remove()
	})
})
