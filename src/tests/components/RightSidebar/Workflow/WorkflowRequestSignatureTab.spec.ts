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
	controllerSpies.refs.hasSignaturePositions = ref(false)
	controllerSpies.refs.canManageSigners = ref(false)
	return {
		useWorkflowController: () => ({
			state: {
				signers: ref([{ localKey: 'signer:1', signRequestId: 1 }]),
				file: controllerSpies.refs.file,
				isCurrentFileDetailed: controllerSpies.refs.isCurrentFileDetailed,
				hasSignaturePositions: controllerSpies.refs.hasSignaturePositions,
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
			canManageSigners: controllerSpies.refs.canManageSigners,
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

	it('opts the stepper and primary action in to motion, keyed on the current document id (Phase 2)', async () => {
		controllerSpies.refs.file.value = { id: 7 }
		const wrapper = createWrapper()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'WorkflowStepper' }).props('motionKey')).toBe(7)
		expect(wrapper.findComponent({ name: 'WorkflowActions' }).props('motionKey')).toBe(7)

		controllerSpies.refs.file.value = { id: 8 }
		await flushPromises()

		expect(wrapper.findComponent({ name: 'WorkflowStepper' }).props('motionKey')).toBe(8)
		expect(wrapper.findComponent({ name: 'WorkflowActions' }).props('motionKey')).toBe(8)
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
		refs.hasSignaturePositions.value = false
		refs.canManageSigners.value = false
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

	it('hides the file actions while loading AND when detail failed; shows them once loaded', async () => {
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		const fileActions = () => wrapper.findComponent({ name: 'WorkflowFileActions' }).exists()
		expect(fileActions()).toBe(false)
		refs.detailLoading.value = false
		await flushPromises()
		expect(fileActions()).toBe(false)
		refs.isCurrentFileDetailed.value = true
		await flushPromises()
		expect(fileActions()).toBe(true)
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

describe('WorkflowRequestSignatureTab.vue detail body and motion hooks (Phase 1)', () => {
	const refs = controllerSpies.refs

	beforeEach(() => {
		vi.clearAllMocks()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
		refs.file.value = { id: 7 }
		refs.isCurrentFileDetailed.value = true
		refs.detailLoading.value = false
		refs.hasSignaturePositions.value = false
		refs.canManageSigners.value = false
	})

	const body = (wrapper: ReturnType<typeof createWrapper>) => wrapper.find('.workflow-detail-body')

	it('renders the detail-dependent cluster inside one wrapper, in the original order', async () => {
		refs.canManageSigners.value = true
		refs.hasSignaturePositions.value = true
		const wrapper = createWrapper()
		await flushPromises()
		const html = body(wrapper).html()
		expect(body(wrapper).exists()).toBe(true)
		const order = ['workflow-stepper-stub', 'workflow-signers-stub', 'workflow-signature-setup-stub', 'workflow-file-actions-stub', 'workflow-actions-stub']
		const positions = order.map((tag) => html.indexOf(tag))
		expect(positions.every((p) => p >= 0)).toBe(true)
		expect([...positions].sort((a, b) => a - b)).toEqual(positions)
	})

	it('keeps VisibleElements outside the wrapper', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		expect(wrapper.find('.visible-elements-legacy-stub').exists()).toBe(true)
		expect(body(wrapper).find('.visible-elements-legacy-stub').exists()).toBe(false)
	})

	it('shows loader, error and body mutually exclusively', async () => {
		const wrapper = createWrapper()
		const state = () => ({
			loading: wrapper.find('.workflow-detail-loading').exists(),
			error: wrapper.find('.workflow-detail-error').exists(),
			body: body(wrapper).exists(),
		})
		await flushPromises()
		expect(state()).toEqual({ loading: false, error: false, body: true })
		refs.isCurrentFileDetailed.value = false
		refs.detailLoading.value = true
		await flushPromises()
		expect(state()).toEqual({ loading: true, error: false, body: false })
		refs.detailLoading.value = false
		await flushPromises()
		expect(state()).toEqual({ loading: false, error: true, body: false })
		refs.isCurrentFileDetailed.value = true
		await flushPromises()
		expect(state()).toEqual({ loading: false, error: false, body: true })
	})

	it('reflects the existing loading state in aria-busy on the tab root', async () => {
		refs.isCurrentFileDetailed.value = false
		refs.detailLoading.value = true
		const wrapper = createWrapper()
		await flushPromises()
		expect(wrapper.find('.workflow-request-signature-tab').attributes('aria-busy')).toBe('true')
		refs.detailLoading.value = false
		refs.isCurrentFileDetailed.value = true
		await flushPromises()
		expect(wrapper.find('.workflow-request-signature-tab').attributes('aria-busy')).toBe('false')
	})

	it('shows the signature-fields card only when the user can manage signers and positions exist', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		const card = () => wrapper.findComponent({ name: 'WorkflowSignatureSetup' }).exists()
		expect(card()).toBe(false)
		refs.canManageSigners.value = true
		await flushPromises()
		expect(card()).toBe(false)
		refs.hasSignaturePositions.value = true
		await flushPromises()
		expect(card()).toBe(true)
	})

	it('keeps the WorkflowActions loading prop wired to isRequestBusy', async () => {
		const wrapper = createWrapper()
		await flushPromises()
		expect(wrapper.findComponent({ name: 'WorkflowActions' }).props('loading')).toBe(true)
	})
})
