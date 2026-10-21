/*
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Compact signer chip contract: email is the only identifying text, clicking
 * the chip body emits edit, the remove control is independent, and the
 * sponsored indicator is gated on the capability AND the persisted draft flag.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { SignerDraft } from '../../../store/publicWorkflow'

const cfg = vi.hoisted(() => ({ sponsorship_enabled: true }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => (key === 'config' ? cfg : def),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string>) =>
		params ? text.replace(/\{(\w+)\}/g, (_m, k) => params[k] ?? '') : text,
}))

async function mountRow(signer: Record<string, unknown>, enabled = true) {
	cfg.sponsorship_enabled = enabled
	vi.resetModules()
	const { default: PwSignerRow } = await import('../../../components/PublicWorkflow/PwSignerRow.vue')
	return mount(PwSignerRow, { props: { signer: signer as unknown as SignerDraft }, global: { stubs: { NcIconSvgWrapper: true } } })
}

afterEach(() => { cfg.sponsorship_enabled = true })

describe('PwSignerRow.vue', () => {
	it('shows the email and does not render the display name', async () => {
		const w = await mountRow({ displayName: 'Grace Achieng', email: 'grace@example.com' })
		expect(w.text()).toContain('grace@example.com')
		expect(w.text()).not.toContain('Grace Achieng')
	})

	it('emits edit from the chip body and remove from the independent control', async () => {
		const w = await mountRow({ displayName: 'Grace', email: 'grace@example.com' })
		await w.find('.pw-chip__open').trigger('click')
		await w.find('.pw-chip__remove').trigger('click')
		expect(w.emitted('edit')).toHaveLength(1)
		expect(w.emitted('remove')).toHaveLength(1)
		// The remove control is a sibling button, not nested in the edit button.
		expect(w.find('.pw-chip__open .pw-chip__remove').exists()).toBe(false)
	})

	it('shows the sponsored indicator only when enabled AND requesterSponsored', async () => {
		expect((await mountRow({ email: 'a@b.com', requesterSponsored: true }, true)).find('.pw-chip__spons').exists()).toBe(true)
		expect((await mountRow({ email: 'a@b.com', requesterSponsored: false }, true)).find('.pw-chip__spons').exists()).toBe(false)
		// Capability off: never show it, even if the draft is marked sponsored.
		expect((await mountRow({ email: 'a@b.com', requesterSponsored: true }, false)).find('.pw-chip__spons').exists()).toBe(false)
	})
})
