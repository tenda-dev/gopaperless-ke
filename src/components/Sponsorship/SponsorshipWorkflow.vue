<template>
	<CreditPurchaseFlow
		v-if="workflowStore.isActive"
		:product-code="DEFAULT_PRODUCT_CODE"
		payment-purpose="credit_purchase"
		:quantity="requiredCredits"
		:allow-quantity-selection="true"
		:presentation="presentation"
		:min-quantity="minQuantity"
		@success="handleSuccess"
		@close="handleClose"
	/>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'

import CreditPurchaseFlow from '@/components/Payments/CreditPurchaseFlow.vue'

import { DEFAULT_PRODUCT_CODE } from '@/constants/product'

import { useEntitlementStore } from '@/store/entitlement'
import { useFilesStore } from '@/store/files'
import { useSponsorshipWorkflowStore } from '@/store/sponsorshipWorkflow'

import type { PaymentPresentation } from '@/payment/types'
import { showInfo } from '@/services/toast'

const workflowStore = useSponsorshipWorkflowStore()
const filesStore = useFilesStore()
const entitlementStore = useEntitlementStore()

const workflow = computed(() => workflowStore.workflow)

const sponsorshipError = computed(
	() => workflow.value?.instruction.errors[0],
)

const requiredCredits = computed(
	() => sponsorshipError.value?.missingCredits ?? 1,
)

const minQuantity = computed(
	() => sponsorshipError.value?.requiredCredits ?? 1,
)

const presentation: PaymentPresentation = {
	modalTitle: 'Buy signing credits',
	summary: {
		title: 'Buy signing credits',
		subtitle: 'You need to buy signing credits to continue.',
	},
}

async function handleSuccess() {
	const pendingRequest = workflow.value?.pendingRequest

	if (!pendingRequest) {
		return
	}

	const response =
		await filesStore.saveOrUpdateSignatureRequest(
			pendingRequest.data,
		)

	if (
		response &&
		'success' in response &&
		response.success === false
	) {
		// The replay failed (or triggered another workflow).
		// Keep the current workflow active.
		return
	}

	workflowStore.reset()

	// Synchronise the remaining credits after reservations
	// have been created by the backend.
	void entitlementStore.refresh()
}

function handleClose() {
	workflowStore.reset()
}
</script>
