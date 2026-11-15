/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

import { FILE_STATUS } from '../../constants.js'
import { useWorkflowState } from '../../composables/useWorkflowState'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, _key: string, fallback: unknown) => fallback,
}))

const holder = vi.hoisted(() => ({ file: {} as Record<string, unknown> }))

vi.mock('../../store/files', () => ({
	useFilesStore: () => ({
		selectedFileId: 5,
		files: { 5: holder.file },
		canAddSigner: () => true,
		canSave: () => true,
		canSign: () => false,
		canValidate: () => true,
	}),
}))

function stepsFor(file: Record<string, unknown>) {
	holder.file = { id: 5, detailsLoaded: true, ...file }
	return useWorkflowState().steps.value.map((s) => s.status)
}

describe('useWorkflowState steps', () => {
	beforeEach(() => { holder.file = {} })

	it('a draft with signers but no positions is on "Setup positions"', () => {
		expect(stepsFor({ status: FILE_STATUS.DRAFT, signers: [{ signRequestId: 1 }], visibleElements: [] }))
			.toEqual(['complete', 'current', 'locked', 'locked'])
	})

	it('a signed document is complete everywhere even when no visible elements were loaded', () => {
		expect(stepsFor({ status: FILE_STATUS.SIGNED, signers: [{ signRequestId: 1 }], visibleElements: [] }))
			.toEqual(['complete', 'complete', 'complete', 'complete'])
	})

	it('a signed document stays complete when signers are absent from the payload', () => {
		expect(stepsFor({ status: FILE_STATUS.SIGNED, signers: [], visibleElements: [] }))
			.toEqual(['complete', 'complete', 'complete', 'complete'])
	})

	it('a signing-in-progress document is past positions', () => {
		expect(stepsFor({ status: FILE_STATUS.SIGNING_IN_PROGRESS, signers: [{ signRequestId: 1 }], visibleElements: [] }))
			.toEqual(['complete', 'complete', 'complete', 'current'])
	})

	it('a fully prepared draft is on "Request signatures"', () => {
		expect(stepsFor({ status: FILE_STATUS.DRAFT, signers: [{ signRequestId: 1 }], visibleElements: [{ id: 1 }] }))
			.toEqual(['complete', 'complete', 'current', 'locked'])
	})
})
