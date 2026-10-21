/*
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration contracts for the public signature-positions composable: signer
 * mapping, the serializer delegation, and that mutations route through the
 * PUBLIC store actions (never an authenticated path). The live PDF-editor
 * interactions require a browser and remain a runtime check.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	createWorkflow: vi.fn(),
	getWorkflow: vi.fn(),
	uploadDocument: vi.fn(),
	saveSigners: vi.fn(async () => ({})),
	saveVisibleElements: vi.fn(async () => ({ uuid: 'u', visibleElements: [] })),
	deleteVisibleElement: vi.fn(async () => ({ uuid: 'u', visibleElements: [] })),
	revokeWorkflow: vi.fn(),
}))
vi.mock('@/services/publicWorkflow', () => api)
vi.mock('@/services/toast.ts', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showInfo: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))
vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: () => ({ libresign: { config: { 'sign-elements': { 'full-signature-width': 350, 'full-signature-height': 100 } } } }),
}))

import { usePublicVisibleElements } from '../../../components/PublicWorkflow/composables/usePublicVisibleElements'
import { usePublicWorkflow } from '../../../store/publicWorkflow'

function setDocument(doc: unknown) {
	usePublicWorkflow().document.value = doc as any
}

beforeEach(() => {
	vi.clearAllMocks()
	setDocument(null)
})

describe('usePublicVisibleElements', () => {
	it('maps the store document signers into summary records', () => {
		setDocument({
			id: 1,
			name: 'lease.pdf',
			signers: [
				{ signRequestId: 101, displayName: 'Asha', identifyMethods: [{ method: 'email', value: 'a@x' }] },
				{ signRequestId: 202, displayName: 'Dan', identifyMethods: [{ method: 'email', value: 'd@x' }] },
			],
		})
		const vis = usePublicVisibleElements()
		expect(vis.signerList.value.map((s) => s.signRequestId)).toEqual([101, 202])
		expect(vis.signerCount.value).toBe(2)
	})

	it('buildVisibleElements returns an empty payload when no editor is attached', () => {
		setDocument({ id: 1, name: 'lease.pdf', signers: [] })
		const vis = usePublicVisibleElements()
		expect(vis.buildVisibleElements()).toEqual([])
	})

	it('pdfReadOnly matches the authenticated editor so existing boxes stay editable on desktop', () => {
		setDocument({ id: 1, name: 'lease.pdf', signers: [] })
		const vis = usePublicVisibleElements()

		// Desktop, not placing: interactive — this is the regression (was always read-only).
		vis.setMobile(false)
		expect(vis.pdfReadOnly.value).toBe(false)

		// Placing is page-level and works read-only on any device.
		vis.placing.value = true
		expect(vis.pdfReadOnly.value).toBe(true)
		vis.placing.value = false

		// Mobile still needs the explicit Edit toggle.
		vis.setMobile(true)
		expect(vis.pdfReadOnly.value).toBe(true)
		vis.editMode.value = true
		expect(vis.pdfReadOnly.value).toBe(false)
	})

	it('save() routes through the PUBLIC saveVisibleElements action', async () => {
		setDocument({ id: 1, name: 'lease.pdf', files: [{ id: 1, name: 'lease', metadata: { p: 1 } }], signers: [] })
		const vis = usePublicVisibleElements()
		await vis.save()
		expect(api.saveVisibleElements).toHaveBeenCalledTimes(1)
	})

	it('handleDeleteSigner persists only when the element has an id, via the PUBLIC delete action', async () => {
		setDocument({ id: 1, name: 'lease.pdf', signers: [] })
		const vis = usePublicVisibleElements()
		await vis.handleDeleteSigner({})
		expect(api.deleteVisibleElement).not.toHaveBeenCalled()
		await vis.handleDeleteSigner({ elementId: 7 })
		expect(api.deleteVisibleElement).toHaveBeenCalledWith(7)
	})
})
