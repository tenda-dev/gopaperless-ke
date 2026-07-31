/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, beforeEach, vi } from 'vitest'
import { createL10nMock } from '../../../testHelpers/l10n.js'
import { createPinia, setActivePinia } from 'pinia'

const filesStoreMock = {
	loading: false,
	getFile: vi.fn(() => ({})),
	getEditableFile: vi.fn(() => ({
		id: 1,
		name: 'test.pdf',
		status: 0,
		signers: [],
		visibleElements: [],
	})),
	saveOrUpdateSignatureRequest: vi.fn(),
}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(() => false),
}))

vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: vi.fn(() => ({
		libresign: {
			config: {
				'sign-elements': {
					'is-available': true,
					'full-signature-width': 200,
					'full-signature-height': 100,
				},
			},
		},
	})),
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => path),
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/event-bus', () => ({
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))

vi.mock('@/services/toast.ts', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('../../../../store/files.js', () => ({
	useFilesStore: vi.fn(() => filesStoreMock),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

describe('useVisibleElementsNext', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		filesStoreMock.getEditableFile.mockReturnValue({
			id: 1,
			name: 'test.pdf',
			status: 0,
			signers: [],
			visibleElements: [],
		})
	})

	it('initializes with default state', async () => {
		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.modal.value).toBe(false)
		expect(state.loading.value).toBe(false)
		expect(state.pdfReady.value).toBe(false)
		expect(state.mode.value).toBe('browse')
		expect(state.focusedSignerId.value).toBeNull()
		expect(state.placing.value).toBe(false)
	})

	it('computes signer count from document signers', async () => {
		filesStoreMock.getEditableFile.mockReturnValue({
			id: 1,
			name: 'test.pdf',
			status: 0,
			signers: [
				{ signRequestId: 1, displayName: 'Alice' },
				{ signRequestId: 2, displayName: 'Bob' },
			],
			visibleElements: [],
		})

		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.signerCount.value).toBe(2)
		expect(state.signerList.value).toHaveLength(2)
	})

	it('canSave is true for draft documents', async () => {
		const { useVisibleElementsNext } = await import(
			'../../../../components/Request/VisibleElementsNext/composables/useVisibleElementsNext.ts'
		)

		const state = useVisibleElementsNext()

		expect(state.canSave.value).toBe(true)
	})
})
