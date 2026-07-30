/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowRequestSignatureTab from '../../../../components/RightSidebar/Workflow/WorkflowRequestSignatureTab.vue'

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@nextcloud/l10n', () => createL10nMock())

// Lightweight stand-ins for the two signature-position editors so the
// feature-flag gating can be asserted without the real (heavy) components.
vi.mock('../../../../components/Request/VisibleElements.vue', () => ({
	__esModule: true,
	default: { name: 'VisibleElements', template: '<div class="visible-elements-legacy-stub" />' },
}))

vi.mock('../../../../components/Request/VisibleElementsNext/VisibleElementsNext.vue', () => ({
	__esModule: true,
	default: { name: 'VisibleElementsNext', template: '<div class="visible-elements-next-stub" />' },
}))

vi.mock('../../../../composables/useWorkflowController.js', async () => {
	const { ref } = await import('vue')
	return {
		useWorkflowController: () => ({
			state: {
				signers: ref([]),
				file: ref({}),
				snapshot: ref({}),
				steps: ref([]),
				canValidate: ref(false),
				shouldLoadDetail: ref(false),
				showDocMdpWarning: ref(false),
				isOriginalFileDeleted: ref(false),
				hasSignersWithDisabledMethods: ref(false),
				isEnvelope: ref(false),
				showSigningProgress: ref(false),
			},
			hasLoading: ref(false),
			isLoadingFileDetail: ref(false),
			signerToEdit: ref(null),
			modalSrc: ref(null),
			methods: ref([]),
			showConfirmRequest: ref(false),
			showConfirmRequestSigner: ref(false),
			selectedSigner: ref(null),
			preserveOrder: ref(false),
			showOrderDiagram: ref(false),
			showEnvelopeFilesDialog: ref(false),
			signingProgress: ref(null),
			signingProgressStatus: ref(null),
			signingProgressStatusText: ref(null),
			currentUserDisplayName: ref('Test User'),
			size: ref('normal'),
			modalTitle: ref(''),
			fileName: ref(''),
			enabledMethods: ref([]),
			isSignerMethodDisabled: ref(false),
			signingOrderDiagramSigners: ref([]),
			canManageSigners: ref(false),
			addSigner: vi.fn(),
			editSigner: vi.fn(),
			customizeMessage: vi.fn(),
			onPreserveOrderChange: vi.fn(),
			updateSigningOrder: vi.fn(),
			confirmSigningOrder: vi.fn(),
			save: vi.fn(),
			request: vi.fn(),
			confirmRequest: vi.fn(),
			sign: vi.fn(),
			validationFile: vi.fn(),
			openFile: vi.fn(),
			openManageFiles: vi.fn(),
			sendNotify: vi.fn(),
			requestSignatureForSigner: vi.fn(),
			confirmRequestSigner: vi.fn(),
			closeModal: vi.fn(),
			debouncedSave: vi.fn(),
		}),
	}
})

vi.mock('../../../../store/files.js', () => ({
	useFilesStore: () => ({
		identifyingSigner: null,
		deleteSigner: vi.fn(),
		disableIdentifySigner: vi.fn(),
	}),
}))

function createWrapper() {
	return mount(WorkflowRequestSignatureTab, {
		global: {
			stubs: {
				WorkflowHeaderCard: true,
				WorkflowStepper: true,
				WorkflowSigners: true,
				WorkflowSignatureSetup: true,
				SigningProgress: true,
				WorkflowFileActions: true,
				WorkflowActions: true,
				EnvelopeFilesList: true,
				IdentifySigner: true,
				SigningOrderDiagram: true,
				NcNoteCard: true,
				NcModal: true,
				NcDialog: true,
				NcButton: true,
				NcLoadingIcon: true,
				NcIconSvgWrapper: true,
			},
		},
	})
}

describe('WorkflowRequestSignatureTab.vue visible elements feature flag', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('uses the legacy VisibleElements editor by default (flag missing)', async () => {
		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'VisibleElements' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'VisibleElementsNext' }).exists()).toBe(false)
	})

	it('uses the legacy VisibleElements editor when the flag is false', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'config') {
				return { visible_elements_next_enabled: false }
			}
			return fallback
		})

		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'VisibleElements' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'VisibleElementsNext' }).exists()).toBe(false)
	})

	it('uses VisibleElementsNext when the flag is enabled', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'config') {
				return { visible_elements_next_enabled: true }
			}
			return fallback
		})

		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'VisibleElementsNext' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'VisibleElements' }).exists()).toBe(false)
	})
})
