/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
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

	function mountComponent(props: { workflow: WorkflowState; loading?: boolean }) {
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
})
