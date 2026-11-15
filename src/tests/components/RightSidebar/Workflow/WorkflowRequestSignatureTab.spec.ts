/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowRequestSignatureTab from '../../../../components/RightSidebar/Workflow/WorkflowRequestSignatureTab.vue'

const loadStateMock = vi.fn()
const controllerSpies = vi.hoisted(() => ({
	deleteSigner: vi.fn(),
	sendNotify: vi.fn(),
	sendWhatsApp: vi.fn(),
	ensureCurrentFileDetail: vi.fn(),
	// Reactive handles created inside the controller mock factory (needs `vue`).
	refs: {} as Record<string, { value: any }>,
}))

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
	controllerSpies.refs.file = ref({})
	controllerSpies.refs.isCurrentFileDetailed = ref(true)
	controllerSpies.refs.detailLoading = ref(false)
	return {
		useWorkflowController: () => ({
			state: {
				signers: ref([{ localKey: 'signer:1', signRequestId: 1 }]),
				file: controllerSpies.refs.file,
				isCurrentFileDetailed: controllerSpies.refs.isCurrentFileDetailed,
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
			pendingSignerActions: ref({ 'signer:1': 'notify' }),
			isRequestBusy: ref(true),
			isDeleteBlocked: ref(false),
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
			sendNotify: controllerSpies.sendNotify,
			sendWhatsApp: controllerSpies.sendWhatsApp,
			deleteSigner: controllerSpies.deleteSigner,
			requestSignatureForSigner: vi.fn(),
			confirmRequestSigner: vi.fn(),
			closeModal: vi.fn(),
			ensureCurrentFileDetail: controllerSpies.ensureCurrentFileDetail,
			debouncedSave: vi.fn(),
		}),
	}
})

vi.mock('../../../../store/files.js', () => ({
	useFilesStore: () => ({
		identifyingSigner: null,
		deleteSigner: vi.fn(),
		disableIdentifySigner: vi.fn(),
		isLoadingDetail: () => controllerSpies.refs.detailLoading.value,
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

describe('WorkflowRequestSignatureTab.vue signer action wiring (WS4)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
	})

	it('passes controller-owned pending state down to WorkflowSigners', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		const signers = wrapper.findComponent({ name: 'WorkflowSigners' })
		expect(signers.props('pendingActions')).toEqual({ 'signer:1': 'notify' })
		// Delete gating comes from isDeleteBlocked, not isRequestBusy (which drives request-level buttons).
		expect(signers.props('requestBusy')).toBe(false)
	})

	it('routes delete, reminder and WhatsApp events to the controller handlers', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		const signers = wrapper.findComponent({ name: 'WorkflowSigners' })
		const signer = { localKey: 'signer:1' }
		signers.vm.$emit('delete-signer', signer)
		signers.vm.$emit('send-reminder', signer)
		signers.vm.$emit('send-whatsapp', signer)
		expect(controllerSpies.deleteSigner).toHaveBeenCalledWith(signer)
		expect(controllerSpies.sendNotify).toHaveBeenCalledWith(signer)
		expect(controllerSpies.sendWhatsApp).toHaveBeenCalledWith(signer)
	})

	it('binds request-level loading to isRequestBusy', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		expect(wrapper.findComponent({ name: 'WorkflowActions' }).props('loading')).toBe(true)
	})
})

describe('WorkflowRequestSignatureTab.vue document-detail presentation (S4)', () => {
	const refs = controllerSpies.refs

	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
		refs.file.value = { id: 7 }
		refs.isCurrentFileDetailed.value = false
		refs.detailLoading.value = false
	})

	function sections(wrapper: ReturnType<typeof createWrapper>) {
		return {
			loading: wrapper.find('.workflow-detail-loading').exists(),
			error: wrapper.find('.workflow-detail-error').exists(),
			signers: wrapper.findComponent({ name: 'WorkflowSigners' }).exists(),
			actions: wrapper.findComponent({ name: 'WorkflowActions' }).exists(),
			stepper: wrapper.findComponent({ name: 'WorkflowStepper' }).exists(),
			header: wrapper.findComponent({ name: 'WorkflowHeaderCard' }).exists(),
		}
	}

	it('shows a loading state, not the workflow body, while the current document is loading', async () => {
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toEqual({ loading: true, error: false, signers: false, actions: false, stepper: false, header: false })
	})

	it('hides the file actions while loading, keeps them for the error and loaded states', async () => {
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(wrapper.findComponent({ name: 'WorkflowFileActions' }).exists()).toBe(false)
		refs.detailLoading.value = false
		await flushPromises()
		expect(wrapper.findComponent({ name: 'WorkflowFileActions' }).exists()).toBe(true)
		refs.isCurrentFileDetailed.value = true
		await flushPromises()
		expect(wrapper.findComponent({ name: 'WorkflowFileActions' }).exists()).toBe(true)
	})

	it('shows an error with Retry when details are required, not loaded and not loading', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toEqual({ loading: false, error: true, signers: false, actions: false, stepper: false, header: false })
	})

	it('retries through ensureCurrentFileDetail(true) only', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		await wrapper.find('.workflow-detail-error').findComponent({ name: 'NcButton' }).trigger('click')
		expect(controllerSpies.ensureCurrentFileDetail).toHaveBeenCalledTimes(1)
		expect(controllerSpies.ensureCurrentFileDetail).toHaveBeenCalledWith(true)
	})

	it('renders the workflow body for an already detailed document with no loading or error UI', async () => {
		refs.isCurrentFileDetailed.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toEqual({ loading: false, error: false, signers: true, actions: true, stepper: true, header: false })
		expect(controllerSpies.ensureCurrentFileDetail).not.toHaveBeenCalled()
	})

	it('gates on "not yet detailed", not on the signer count (list summaries report 0 signers until detail arrives)', async () => {
		// The mocked state exposes no signers-based flag at all: only detail state decides.
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toMatchObject({ loading: true, stepper: false, actions: false })
	})

	it('does not gate a non-persisted file (negative id)', async () => {
		refs.file.value = { id: -3 }
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toMatchObject({ loading: false, error: false, stepper: true, actions: true })
	})

	it('does not show an error when the file has no numeric id (nothing can be fetched)', async () => {
		refs.file.value = {}
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper)).toMatchObject({ loading: false, error: false, actions: true })
	})

	it('moves loading -> loaded without a Retry flash, and never fetches by itself', async () => {
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(sections(wrapper).error).toBe(false)
		refs.detailLoading.value = false
		refs.isCurrentFileDetailed.value = true
		await flushPromises()
		expect(sections(wrapper)).toEqual({ loading: false, error: false, signers: true, actions: true, stepper: true, header: false })
		expect(controllerSpies.ensureCurrentFileDetail).not.toHaveBeenCalled()
	})
})
