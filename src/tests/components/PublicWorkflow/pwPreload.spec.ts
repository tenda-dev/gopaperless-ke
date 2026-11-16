/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

// Keep the heavy pdf-elements editor out of the test: the loader only needs to
// resolve to *something* for the promise-reuse behaviour to be observable.
vi.mock('../../../components/Request/VisibleElementsNext/PdfEditorNext.vue', () => ({
	default: { name: 'PdfEditorNext', template: '<div />' },
}))

import { loadFieldEditor } from '../../../components/PublicWorkflow/pwPreload'

describe('pwPreload.loadFieldEditor', () => {
	it('reuses a single import promise across calls (no duplicate preload)', () => {
		const first = loadFieldEditor()
		const second = loadFieldEditor()
		expect(second).toBe(first)
	})

	it('resolves to the field-placement editor module', async () => {
		const mod = await loadFieldEditor() as { default: { name: string } }
		expect(mod.default.name).toBe('PdfEditorNext')
	})
})
