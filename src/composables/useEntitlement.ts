import { computed, ref, watchEffect } from 'vue'

import {
	getCurrentEntitlement,
	type CurrentEntitlement,
} from '@/payment/entitlement'

export function useEntitlement(
	productCode: string,
) {

	const loading = ref(false)

	const error = ref<string | null>(null)

	const entitlement =
		ref<CurrentEntitlement | null>(null)

	async function loadEntitlement() {

		loading.value = true
		error.value = null

		try {

			entitlement.value =
				await getCurrentEntitlement(
					productCode
				)

		} catch (err: any) {

			error.value =
				err?.message ||
				'Failed to load entitlement'

		} finally {

			loading.value = false
		}
	}

	watchEffect(() => {

		if (productCode) {
			loadEntitlement()
		}
	})

	const remainingUses = computed(() => {
		return entitlement.value?.remainingUses ?? 0
	})

	const hasEntitlement = computed(() => {
		return remainingUses.value > 0
	})

	return {
		loading,
		error,
		entitlement,
		remainingUses,
		hasEntitlement,
		refresh: loadEntitlement,
	}
}
