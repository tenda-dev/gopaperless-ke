/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Transition } from 'vue'
import { mount } from '@vue/test-utils'

import {
	mdiAccountPlusOutline,
	mdiCheckCircleOutline,
	mdiDraw,
	mdiProgressClock,
	mdiSend,
	mdiSignatureFreehand,
} from '@mdi/js'

import { createL10nMock } from '../../../testHelpers/l10n.js'
import WorkflowActions from '../../../../components/RightSidebar/Workflow/WorkflowActions.vue'

import { FILE_STATUS, SIGN_REQUEST_STATUS } from '../../../../constants.js'
import type { WorkflowState, WorkflowPrimaryAction } from '../../../../composables/useWorkflowState'

vi.mock('@nextcloud/l10n', () => createL10nMock())

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcIconSvgWrapper', () => ({
	default: {
		name: 'NcIconSvgWrapper',
		props: ['path'],
		template: '<i class="nc-icon-svg-wrapper-stub" :data-path="path" />',
	},
}))

function createWorkflowState(overrides: Partial<WorkflowState> = {}): WorkflowState {
	return {
		workflow: {
			currentStage: 'prepare',
			isDraft: true,
			isBlocked: true,
			isCompleted: false,
		},
		steps: [],
		primaryAction: 'add-signer',
		statusVariant: 'neutral',
		statusLabel: 'Draft',
		statusSubtitle: '',
		isReady: true,
		file: { status: FILE_STATUS.DRAFT },
		signers: [],
		isEnvelope: false,
		envelopeFilesCount: 0,
		isOriginalFileDeleted: false,
		isDocMdpProtected: false,
		showDocMdpWarning: false,
		hasSigners: false,
		totalSigners: 0,
		signersCount: 0,
		hasDraftSigners: false,
		hasSignersWithDisabledMethods: false,
		hasVisibleElements: true,
		hasSignaturePositions: true,
		isOrderedNumeric: false,
		isOrderedSigning: false,
		isAdminFlowForced: false,
		showSigningOrderOptions: false,
		showPreserveOrder: false,
		showViewOrderButton: false,
		shouldShowOrderedOptions: false,
		showSaveButton: false,
		showRequestButton: false,
		showSigningProgress: false,
		shouldHighlightSetupSignPositionsButton: false,
		isCurrentFileDetailed: true,
		shouldLoadDetail: false,
		canAddSigner: true,
		canSave: true,
		canSign: false,
		canValidate: false,
		...overrides,
	} as WorkflowState
}

describe('WorkflowActions.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	function mountComponent(props: { workflow: WorkflowState; loading?: boolean; motionKey?: number }) {
		return mount(WorkflowActions, {
			props,
		})
	}

	it('short-circuits the primary action to sign-document when the requester can sign now', () => {
		const wrapper = mountComponent({
			workflow: createWorkflowState({
				primaryAction: 'request-signatures',
				file: { status: FILE_STATUS.ABLE_TO_SIGN },
				signers: [
					{ me: true, signed: false, status: SIGN_REQUEST_STATUS.ABLE_TO_SIGN },
				],
			}),
		})

		expect(wrapper.text()).toContain('Proceed to sign')
		expect((wrapper.vm as unknown as Record<string, unknown>).primaryAction).toBe('sign-document')
	})

	describe('secondary "Request signatures" button', () => {
		it('renders when the current user is a signer, primary action is not request-signatures, other draft signers exist and the workflow can request signatures', () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'setup-positions',
					file: { status: FILE_STATUS.ABLE_TO_SIGN },
					signers: [
						{ me: true, signed: false, status: SIGN_REQUEST_STATUS.ABLE_TO_SIGN },
						{ me: false, signed: false, status: SIGN_REQUEST_STATUS.DRAFT },
					],
					showRequestButton: true,
					isReady: true,
				}),
			})

			const secondaryButtons = wrapper.findAll('.workflow-secondary-action-button')
			expect(secondaryButtons.length).toBe(1)
			expect(secondaryButtons[0].text()).toContain('Request signatures')
		})

		it('does not render a secondary "Request signatures" button when the primary action already is request-signatures', () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'request-signatures',
					file: { status: FILE_STATUS.DRAFT },
					signers: [
						{ me: true, signed: false, status: SIGN_REQUEST_STATUS.ABLE_TO_SIGN },
						{ me: false, signed: false, status: SIGN_REQUEST_STATUS.DRAFT },
					],
					showRequestButton: true,
					isReady: true,
					hasSigners: true,
				}),
			})

			const requestSignaturesButtons = wrapper
				.findAll('.workflow-secondary-action-button')
				.filter(button => button.text().includes('Request signatures'))

			expect(requestSignaturesButtons.length).toBe(0)
		})
	})

	describe('primary action label and icon mapping', () => {
		it.each([
			{ action: 'add-signer' as const, label: 'Add signer', icon: mdiAccountPlusOutline },
			{ action: 'setup-positions' as const, label: 'Setup signature positions', icon: mdiDraw },
			{ action: 'request-signatures' as const, label: 'Request signatures', icon: mdiSend },
			{ action: 'sign-document' as const, label: 'Proceed to sign', icon: mdiSignatureFreehand },
			{ action: 'view-progress' as const, label: 'View signing progress', icon: mdiProgressClock },
			{ action: 'completed' as const, label: 'Completed', icon: mdiCheckCircleOutline },
			{ action: 'unknown-action' as unknown as WorkflowPrimaryAction, label: 'Continue', icon: mdiSend },
		])('renders label "$label" and icon for primary action "$action"', ({ action, label, icon }) => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({ primaryAction: action }),
			})

			expect(wrapper.text()).toContain(label)
			expect((wrapper.vm as unknown as Record<string, unknown>).primaryActionIcon).toBe(icon)
		})
	})

	describe('signature-positions helper text', () => {
		const missingFields = { hasSigners: true, hasVisibleElements: false, isReady: false }

		it('is shown while positions are still to be set up', () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({ ...missingFields, primaryAction: 'setup-positions' }),
			})
			expect(wrapper.text()).toContain('Visible signature positions must be configured.')
		})

		it.each(['completed', 'view-progress'] as WorkflowPrimaryAction[])('is not shown once the workflow is past positions (%s)', (primaryAction) => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({ ...missingFields, primaryAction }),
			})
			expect(wrapper.text()).not.toContain('Visible signature positions must be configured.')
		})
	})

	describe('blocked state', () => {
		it('adds the blocked class when the workflow is not ready and primary action is not request-signatures', () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'add-signer',
					isReady: false,
				}),
			})

			expect(wrapper.find('.workflow-primary-action').classes()).toContain('workflow-primary-action--blocked')
		})

		it('does not add the blocked class when the workflow is ready', () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'add-signer',
					isReady: true,
				}),
			})

			expect(wrapper.find('.workflow-primary-action').classes()).not.toContain('workflow-primary-action--blocked')
		})
	})

	describe('button clicks', () => {
		it.each([
			{ action: 'add-signer' as const, event: 'add-signer' },
			{ action: 'setup-positions' as const, event: 'setup-positions' },
			{ action: 'request-signatures' as const, event: 'request-signatures' },
			{ action: 'sign-document' as const, event: 'sign-document' },
			{ action: 'view-progress' as const, event: 'view-progress' },
		])('emits "$event" when the primary "$action" button is clicked', async ({ action, event }) => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({ primaryAction: action }),
			})

			await wrapper.find('.workflow-primary-action-button').trigger('click')

			expect(wrapper.emitted(event)).toHaveLength(1)
		})

		it('emits "request-signatures" when the secondary request signatures button is clicked', async () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'setup-positions',
					file: { status: FILE_STATUS.ABLE_TO_SIGN },
					signers: [
						{ me: true, signed: false, status: SIGN_REQUEST_STATUS.ABLE_TO_SIGN },
						{ me: false, signed: false, status: SIGN_REQUEST_STATUS.DRAFT },
					],
					showRequestButton: true,
					isReady: true,
				}),
			})

			await wrapper.find('.workflow-secondary-action-button').trigger('click')

			expect(wrapper.emitted('request-signatures')).toHaveLength(1)
		})

		it('emits "secondary-action" when the review signers secondary button is clicked', async () => {
			const wrapper = mountComponent({
				workflow: createWorkflowState({
					primaryAction: 'request-signatures',
					file: { status: FILE_STATUS.DRAFT },
					signers: [
						{ me: false, signed: false, status: SIGN_REQUEST_STATUS.DRAFT },
					],
					isReady: true,
					hasSigners: true,
				}),
			})

			await wrapper.find('.workflow-secondary-action-button').trigger('click')

			expect(wrapper.emitted('secondary-action')).toHaveLength(1)
		})
	})
	describe('stage-change motion (Phase 2)', () => {
		let frames: Array<() => void>

		beforeEach(() => {
			frames = []
			vi.stubGlobal('requestAnimationFrame', (cb: () => void) => {
				frames.push(cb)
				return frames.length
			})
			vi.stubGlobal('cancelAnimationFrame', () => {
				frames = []
			})
		})

		afterEach(() => {
			vi.unstubAllGlobals()
		})

		const flushFrames = () => {
			for (let i = 0; i < 2; i++) {
				const queued = frames
				frames = []
				queued.forEach((cb) => cb())
			}
		}

		const stage = (primaryAction: WorkflowPrimaryAction, status: number = FILE_STATUS.DRAFT) => createWorkflowState({
			primaryAction,
			file: { status },
			signers: [{ me: false, signed: false, status: SIGN_REQUEST_STATUS.DRAFT }],
			hasSigners: true,
		})

		const bodyTransition = (wrapper: ReturnType<typeof mountComponent>) => wrapper.findAll('transition-stub')[0]

		it('wraps icon + content in one block keyed on the stage, keeping the buttons outside it', () => {
			const wrapper = mountComponent({ workflow: stage('request-signatures'), motionKey: 1 })
			const body = wrapper.find('.workflow-primary-action-body')

			expect(body.exists()).toBe(true)
			expect(body.find('.workflow-primary-action-icon').exists()).toBe(true)
			expect(body.find('.workflow-primary-action-title').exists()).toBe(true)
			expect(body.find('.workflow-primary-action-button').exists()).toBe(false)
			expect(wrapper.find('.workflow-primary-action-button').exists()).toBe(true)
		})

		it('does not run css transitions without a motion-key', () => {
			const wrapper = mountComponent({ workflow: stage('request-signatures') })

			expect(bodyTransition(wrapper).attributes('css')).toBe('false')
		})

		it('uses the plain swap for an ordinary stage change of the same document', async () => {
			const wrapper = mountComponent({ workflow: stage('setup-positions'), motionKey: 1 })

			await wrapper.setProps({ workflow: stage('request-signatures') })

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-swap')
			expect(bodyTransition(wrapper).attributes('css')).toBe('true')
		})

		it('arms the one-shot completion enter when the SAME document becomes completed', async () => {
			const wrapper = mountComponent({ workflow: stage('view-progress', FILE_STATUS.ABLE_TO_SIGN), motionKey: 1 })

			await wrapper.setProps({ workflow: stage('completed', FILE_STATUS.SIGNED) })

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-complete')
		})

		it('disarms after the completed block has entered', async () => {
			const wrapper = mountComponent({ workflow: stage('view-progress', FILE_STATUS.ABLE_TO_SIGN), motionKey: 1 })
			await wrapper.setProps({ workflow: stage('completed', FILE_STATUS.SIGNED) })

			const transition = wrapper.findAllComponents(Transition)[0] as unknown as { vm: { $emit: (e: string) => void } }
			transition.vm.$emit('after-enter')
			await wrapper.vm.$nextTick()

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-swap')
		})

		it('does not arm when opening an already-completed document', () => {
			const wrapper = mountComponent({ workflow: stage('completed', FILE_STATUS.SIGNED), motionKey: 1 })

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-swap')
		})

		it('does not arm (and is still) when the document changes in the same update', async () => {
			const wrapper = mountComponent({ workflow: stage('view-progress', FILE_STATUS.ABLE_TO_SIGN), motionKey: 1 })

			await wrapper.setProps({ workflow: stage('completed', FILE_STATUS.SIGNED), motionKey: 2 })

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-swap')
			expect(bodyTransition(wrapper).attributes('css')).toBe('false')

			flushFrames()
			await wrapper.vm.$nextTick()
			expect(bodyTransition(wrapper).attributes('css')).toBe('true')
		})

		it('does not arm without a motion-key', async () => {
			const wrapper = mountComponent({ workflow: stage('view-progress', FILE_STATUS.ABLE_TO_SIGN) })

			await wrapper.setProps({ workflow: stage('completed', FILE_STATUS.SIGNED) })

			expect(bodyTransition(wrapper).attributes('name')).toBe('gp-swap')
		})

		it('swaps the button label through its own transition', () => {
			const wrapper = mountComponent({ workflow: stage('request-signatures'), motionKey: 1 })
			const label = wrapper.find('.workflow-primary-action-button .workflow-primary-action-label')

			expect(label.exists()).toBe(true)
			expect(label.text().length).toBeGreaterThan(0)
		})
	})
})
