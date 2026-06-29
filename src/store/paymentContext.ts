import { defineStore } from 'pinia'
import { ref } from 'vue'

export enum PaymentFlowType {
	ONE_TIME = 'ONE_TIME',
	CREDIT_PACK = 'CREDIT_PACK',
	SUBSCRIPTION = 'SUBSCRIPTION',
}

export const usePaymentContextStore = defineStore(
	'paymentContext',
	() => {

		const flowType = ref<PaymentFlowType>(
			PaymentFlowType.ONE_TIME,
		)

		function setFlowType(
			type: PaymentFlowType,
		): void {
			flowType.value = type
		}

		function reset(): void {
			flowType.value =
				PaymentFlowType.ONE_TIME
		}

		return {
			flowType,
			setFlowType,
			reset,
		}
	},
)
