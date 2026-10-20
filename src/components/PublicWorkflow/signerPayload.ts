/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure mapping from the public workflow's editable SignerDraft rows to the
 * `saveSigners` API payload. Extracted from PublicWorkflowShell so the
 * draft -> payload contract (sponsorship intent conversion, custom-message
 * mapping, capability gating, the 500-char cap) is unit-testable without
 * mounting the shell. DRAFT-only: `notify` is always 0; no send/sign path.
 */
import type { SignerDraft } from '../../store/publicWorkflow'

export const MAX_SIGNER_MESSAGE = 500

/**
 * Identity-conflict prevention for the public draft: true when `email` matches
 * any of `existingEmails` (the OTHER signers already in the draft),
 * case-insensitively and trimmed. Empty input is never a duplicate.
 */
export function isDuplicateEmail(email: string, existingEmails: string[]): boolean {
	const value = email.trim().toLowerCase()
	return value.length > 0 && existingEmails.some((e) => e.trim().toLowerCase() === value)
}

export interface PublicSignerPayload {
	displayName: string
	identifyMethods: Array<{ method: string, value: string }>
	notify: number
	description?: string
	sponsorship?: { type: 'requester' | 'self' }
}

/**
 * Build the DRAFT saveSigners payload.
 *
 * - Rows without an email are dropped (nothing to identify a signer by).
 * - `description` (custom message) is trimmed and capped to MAX_SIGNER_MESSAGE;
 *   omitted entirely when empty. The shared DRAFT save does not overwrite an
 *   existing description with an empty value (see report), so omitting an empty
 *   message matches the authenticated flow rather than clearing it.
 * - `sponsorship.type` is derived from requesterSponsored ONLY when the
 *   capability is enabled; otherwise no sponsorship preference is sent.
 */
/**
 * Fold the requester into the signer list as a genuine email signer when they
 * have opted to sign and supplied an email — so a real DRAFT sign request is
 * created for them and the editor can place their field against a real id. It
 * is deduped case-insensitively: if the requester email is already a signer
 * (manually added, or hydrated after a prior save), no second entry is created.
 */
export function withRequesterSigner(
	signers: SignerDraft[],
	opts: { requesterEmail: string | null, requesterIsSigner: boolean },
): SignerDraft[] {
	if (!opts.requesterIsSigner) {
		return signers
	}
	const email = (opts.requesterEmail ?? '').trim()
	if (email === '') {
		return signers
	}
	const already = signers.some((s) => s.email.trim().toLowerCase() === email.toLowerCase())
	if (already) {
		return signers
	}
	return [...signers, { displayName: '', email, requesterSponsored: false, description: '' }]
}

export function buildSignerPayload(
	signers: SignerDraft[],
	opts: { sponsorshipEnabled: boolean },
): PublicSignerPayload[] {
	return signers
		.filter((s) => s.email.trim())
		.map((s) => {
			const description = (s.description ?? '').trim().slice(0, MAX_SIGNER_MESSAGE)
			return {
				displayName: s.displayName.trim(),
				identifyMethods: [{ method: 'email', value: s.email.trim() }],
				notify: 0,
				...(description ? { description } : {}),
				...(opts.sponsorshipEnabled
					? { sponsorship: { type: s.requesterSponsored ? 'requester' : 'self' } as const }
					: {}),
			}
		})
}
