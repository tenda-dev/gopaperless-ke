/*
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Store-level persistence contracts for signer sponsorship intent + custom
 * message: resume hydration, save-response hydration, the persisted-state
 * indicator (requesterSponsored derived from sponsorship.type), no duplicate
 * signers after repeated saves, and failed-save input preservation.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	createWorkflow: vi.fn(),
	getWorkflow: vi.fn(),
	uploadDocument: vi.fn(),
	saveSigners: vi.fn(),
	saveRequester: vi.fn(),
	saveVisibleElements: vi.fn(async () => ({ uuid: 'u', visibleElements: [] })),
	deleteVisibleElement: vi.fn(async () => ({ uuid: 'u', visibleElements: [] })),
	revokeWorkflow: vi.fn(),
}))
vi.mock('@/services/publicWorkflow', () => api)
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

import { usePublicWorkflow } from '../../../store/publicWorkflow'

const twoSignerDoc = {
	uuid: 'doc-1',
	name: 'lease.pdf',
	signers: [
		{ signRequestId: 1, displayName: 'Grace', email: 'grace@example.com', sponsorship: { type: 'requester' }, description: 'Please sign page 2.' },
		{ signRequestId: 2, displayName: 'Brian', identifyMethods: [{ method: 'email', value: 'brian@example.com' }], sponsorship: { type: 'self' } },
	],
}

const state = (document: unknown) => ({ status: 'active', expiresAt: '', hasDocument: true, fileUuid: 'doc-1', document })

beforeEach(() => {
	vi.clearAllMocks()
	const store = usePublicWorkflow()
	store.signers.value = []
	store.document.value = null
	store.requesterEmail.value = null
	store.requesterIsSigner.value = false
})

describe('public workflow signer persistence', () => {
	it('hydrates sponsorship intent and custom message on resume', async () => {
		api.getWorkflow.mockResolvedValue(state(twoSignerDoc))
		const store = usePublicWorkflow()
		await store.resume()

		expect(store.signers.value).toHaveLength(2)
		expect(store.signers.value[0]).toMatchObject({ email: 'grace@example.com', requesterSponsored: true, description: 'Please sign page 2.' })
		// requester vs self is the persisted indicator source, not a dialog toggle.
		expect(store.signers.value[1]).toMatchObject({ email: 'brian@example.com', requesterSponsored: false, description: '' })
	})

	it('hydrates from the save response (server authoritative)', async () => {
		api.saveSigners.mockResolvedValue(twoSignerDoc)
		const store = usePublicWorkflow()
		await store.saveSigners([{ displayName: 'Grace', identifyMethods: [{ method: 'email', value: 'grace@example.com' }], notify: 0 }])
		expect(store.signers.value[0].requesterSponsored).toBe(true)
		expect(store.signers.value[0].description).toBe('Please sign page 2.')
	})

	it('does not duplicate signers across repeated saves', async () => {
		api.saveSigners.mockResolvedValue(twoSignerDoc)
		const store = usePublicWorkflow()
		await store.saveSigners([])
		await store.saveSigners([])
		expect(store.signers.value).toHaveLength(2)
	})

	it('preserves unsaved input when a save fails', async () => {
		const store = usePublicWorkflow()
		store.signers.value = [{ displayName: 'Unsaved', email: 'unsaved@example.com', requesterSponsored: true, description: 'draft note' }]
		api.saveSigners.mockRejectedValue(new Error('boom'))
		await expect(store.saveSigners([])).rejects.toThrow()
		expect(store.signers.value).toEqual([
			{ displayName: 'Unsaved', email: 'unsaved@example.com', requesterSponsored: true, description: 'draft note' },
		])
	})

	it('saveRequester forwards email + self-signer intent to the API', async () => {
		api.saveRequester.mockResolvedValue(undefined)
		const store = usePublicWorkflow()
		await store.saveRequester('me@example.com', true)
		expect(api.saveRequester).toHaveBeenCalledWith('me@example.com', true)
	})

	it('hydrates requester email + self-signer intent on resume', async () => {
		api.getWorkflow.mockResolvedValue({ ...state(twoSignerDoc), requesterEmail: 'me@example.com', requesterIsSigner: true })
		const store = usePublicWorkflow()
		await store.resume()
		expect(store.requesterEmail.value).toBe('me@example.com')
		expect(store.requesterIsSigner.value).toBe(true)
	})

	it('defaults requester intent to empty when the resume payload omits it', async () => {
		api.getWorkflow.mockResolvedValue(state(twoSignerDoc))
		const store = usePublicWorkflow()
		await store.resume()
		expect(store.requesterEmail.value).toBeNull()
		expect(store.requesterIsSigner.value).toBe(false)
	})

	it('saveRequester mirrors the persisted intent into the store (survives without reload)', async () => {
		api.saveRequester.mockResolvedValue(undefined)
		const store = usePublicWorkflow()
		await store.saveRequester('me@example.com', true)
		expect(store.requesterEmail.value).toBe('me@example.com')
		expect(store.requesterIsSigner.value).toBe(true)
	})

	it('preserves signer order on resume (insertion order, no reshuffle)', async () => {
		const ordered = {
			uuid: 'doc-1',
			signers: [
				{ signRequestId: 7, email: 'first@example.com' },
				{ signRequestId: 3, email: 'second@example.com' },
				{ signRequestId: 9, email: 'third@example.com' },
			],
		}
		api.getWorkflow.mockResolvedValue(state(ordered))
		const store = usePublicWorkflow()
		await store.resume()
		expect(store.signers.value.map((s) => s.email)).toEqual([
			'first@example.com', 'second@example.com', 'third@example.com',
		])
	})
})
