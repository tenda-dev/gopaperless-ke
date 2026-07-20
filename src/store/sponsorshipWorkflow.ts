import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { showInfo } from '@/services/toast'

export interface PendingWorkflowRequest {
	method: string
	url: string
	data: Record<string, unknown>
}

export interface SponsorshipWorkflowError {
	message: string
	requiredCredits: number
	availableCredits: number
	missingCredits: number
	blockingSignRequestIds: number[]
}

export interface PurchaseSigningCreditsInstruction {
	action: 'purchase_signing_credits'
	errors: SponsorshipWorkflowError[]
}

export type SponsorshipWorkflowInstruction =
	| PurchaseSigningCreditsInstruction


/**
 * Represents an interrupted sponsorship workflow.
 *
 * The backend issues an instruction (for example, purchasing additional
 * signing credits) and the original request is preserved so it can be
 * resumed automatically once the instruction has been completed.
 */
export interface SponsorshipWorkflow {
	instruction: SponsorshipWorkflowInstruction
	pendingRequest: PendingWorkflowRequest
}

export const useSponsorshipWorkflowStore = defineStore(
	'sponsorshipWorkflow',
	() => {
		const workflow = ref<SponsorshipWorkflow | null>(null)

		const isActive = computed(() => workflow.value !== null)

		function start(nextWorkflow: SponsorshipWorkflow) {
			workflow.value = nextWorkflow

			showInfo(
				'This request requires signing credits.',
				true,
				false,
				`Because you're sponsoring one or more signers,
				additional signing credits are needed before
				the request can be sent. Any unused credits remain
				available for future signature requests.`,
				15000,
			)
		}

		function reset() {
			workflow.value = null
		}

		return {
			workflow,
			isActive,
			start,
			reset,
		}
	},
)
