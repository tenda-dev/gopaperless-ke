/*
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The draft -> saveSigners payload contract: sponsorship-intent conversion,
 * capability gating, custom-message mapping + 500-char cap, and empty-row
 * dropping. Pure function, no mounting.
 */
import { describe, expect, it } from 'vitest'

import { buildSignerPayload, isDuplicateEmail, MAX_SIGNER_MESSAGE, withRequesterSigner } from '../../../components/PublicWorkflow/signerPayload'
import type { SignerDraft } from '../../../store/publicWorkflow'

const row = (o: Partial<SignerDraft> = {}): SignerDraft => ({ displayName: 'Grace', email: 'g@example.com', ...o })

describe('buildSignerPayload', () => {
	it('always sends an email identify method and notify:0 (DRAFT-only)', () => {
		const [p] = buildSignerPayload([row()], { sponsorshipEnabled: false })
		expect(p.identifyMethods).toEqual([{ method: 'email', value: 'g@example.com' }])
		expect(p.notify).toBe(0)
	})

	it('drops rows without an email', () => {
		const out = buildSignerPayload([row({ email: '   ' }), row({ email: 'a@b.com' })], { sponsorshipEnabled: true })
		expect(out).toHaveLength(1)
		expect(out[0].identifyMethods[0].value).toBe('a@b.com')
	})

	it('omits sponsorship entirely when the capability is disabled', () => {
		const [p] = buildSignerPayload([row({ requesterSponsored: true })], { sponsorshipEnabled: false })
		expect(p.sponsorship).toBeUndefined()
	})

	it('maps requesterSponsored to sponsorship.type when enabled', () => {
		const [yes] = buildSignerPayload([row({ requesterSponsored: true })], { sponsorshipEnabled: true })
		const [no] = buildSignerPayload([row({ requesterSponsored: false })], { sponsorshipEnabled: true })
		expect(yes.sponsorship).toEqual({ type: 'requester' })
		expect(no.sponsorship).toEqual({ type: 'self' })
	})

	it('defaults to self when enabled and requesterSponsored is undefined', () => {
		const [p] = buildSignerPayload([row()], { sponsorshipEnabled: true })
		expect(p.sponsorship).toEqual({ type: 'self' })
	})

	it('maps a custom message to description, trimmed; omits when empty', () => {
		const [withMsg] = buildSignerPayload([row({ description: '  please sign page 2  ' })], { sponsorshipEnabled: false })
		const [noMsg] = buildSignerPayload([row({ description: '   ' })], { sponsorshipEnabled: false })
		expect(withMsg.description).toBe('please sign page 2')
		expect('description' in noMsg).toBe(false)
	})

	it('caps the message at 500 characters', () => {
		const [p] = buildSignerPayload([row({ description: 'x'.repeat(777) })], { sponsorshipEnabled: false })
		expect(p.description).toHaveLength(MAX_SIGNER_MESSAGE)
	})
})

describe('isDuplicateEmail', () => {
	const existing = ['grace@example.com', 'Brian@Example.com']
	it('matches case-insensitively and trimmed', () => {
		expect(isDuplicateEmail('  GRACE@example.com ', existing)).toBe(true)
		expect(isDuplicateEmail('brian@example.com', existing)).toBe(true)
	})
	it('is false for a new email or empty input', () => {
		expect(isDuplicateEmail('new@example.com', existing)).toBe(false)
		expect(isDuplicateEmail('   ', existing)).toBe(false)
	})
})

describe('withRequesterSigner', () => {
	const manual = (): SignerDraft[] => [row({ email: 'a@b.com' }), row({ email: 'c@d.com' })]

	it('returns the list unchanged when self-signing is off', () => {
		const out = withRequesterSigner(manual(), { requesterEmail: 'me@x.com', requesterIsSigner: false })
		expect(out).toHaveLength(2)
	})

	it('returns the list unchanged when the requester email is blank', () => {
		const out = withRequesterSigner(manual(), { requesterEmail: '   ', requesterIsSigner: true })
		expect(out).toHaveLength(2)
	})

	it('appends the requester as a genuine email signer when opted in', () => {
		const out = withRequesterSigner(manual(), { requesterEmail: 'me@x.com', requesterIsSigner: true })
		expect(out).toHaveLength(3)
		expect(out[2].email).toBe('me@x.com')
		// Folded in cleanly: no stale sponsorship intent, no custom message.
		expect(out[2].requesterSponsored).toBe(false)
		expect(out[2].signRequestId).toBeUndefined()
	})

	it('does not duplicate when the requester email already exists (case-insensitive)', () => {
		const out = withRequesterSigner(manual(), { requesterEmail: '  A@B.COM ', requesterIsSigner: true })
		expect(out).toHaveLength(2)
	})

	it('never mutates the input array', () => {
		const input = manual()
		withRequesterSigner(input, { requesterEmail: 'me@x.com', requesterIsSigner: true })
		expect(input).toHaveLength(2)
	})
})
