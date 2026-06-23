import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import {
	getCurrentEntitlement,
	consumeEntitlement,
	type ConsumeEntitlementPayload,
	type CurrentEntitlement,
	checkEntitlement,
} from '@/payment/entitlement'
import { DEFAULT_PRODUCT_CODE } from '@/constants/product'

export const useEntitlementStore = defineStore(
	'entitlement',
	() => {

		const entitlements = ref<
			Record<string, CurrentEntitlement | null>
		>({})

		const loading = ref(false)

		const error = ref<string | null>(null)

		const initialised = ref(false)

		const loadedProducts = ref(
			new Set<string>(),
		)

		async function initialise(
			productCode = DEFAULT_PRODUCT_CODE,
		) {
			if (
				loadedProducts.value.has(
					productCode,
				)
			) {
				return
			}

			await load(productCode)

			loadedProducts.value.add(
				productCode,
			)
		}

		async function load(
			productCode = DEFAULT_PRODUCT_CODE,
		) {

			loading.value = true
			error.value = null

			try {

				entitlements.value[
					productCode
				] = await getCurrentEntitlement(
					productCode,
				)

			} catch (err: any) {

				error.value =
					err?.message ??
					'Failed to load entitlement'

			} finally {

				loading.value = false
			}
		}

		async function refresh(
			productCode = DEFAULT_PRODUCT_CODE,
		) {
			await load(productCode)
		}

		function decrementLocally(
			productCode: string = DEFAULT_PRODUCT_CODE,
			amount = 1,
		) {

			const entitlement =
				entitlements.value[
				productCode
				]

			if (!entitlement) {
				return
			}

			entitlement.remainingUses =
				Math.max(
					0,
					(entitlement.remainingUses ?? 0)
					- amount,
				)
		}

		async function consume(
			payload: ConsumeEntitlementPayload,
		) {
			const productCode =
				payload.productCode ??
				DEFAULT_PRODUCT_CODE

			try {

				const success =
					await consumeEntitlement(
						payload,
					)

				if (!success) {
					return false
				}

				/**
				 * Immediate UI update.
				 */
				decrementLocally(
					productCode,
				)

				/**
				 * Re-sync with backend.
				 *
				 * Fire-and-forget.
				 */
				void refresh(
					productCode,
				)

				return true

			} catch (err) {

				/**
				 * CRITICAL:
				 *
				 * Entitlement consumption must
				 * NEVER break signing flow.
				 */

				console.error(
					`[EntitlementStore] ${productCode} Consumption failed`,
					err,
				)

				return false
			}
		}

		function getEntitlement(
			productCode = DEFAULT_PRODUCT_CODE,
		) {

			return computed(() =>
				entitlements.value[
				productCode
				] ?? null,
			)
		}

		function getRemainingUses(
			productCode = DEFAULT_PRODUCT_CODE,
		) {

			return computed(() =>
				entitlements.value[
					productCode
				]?.remainingUses ?? 0,
			)
		}

		function hasEntitlement(
			productCode = DEFAULT_PRODUCT_CODE,
		) {

			return computed(() =>
				(
					entitlements.value[
						productCode
					]?.remainingUses ?? 0
				) > 0,
			)
		}


		async function check(
			productCode = DEFAULT_PRODUCT_CODE,
		) {
			try {
				return await checkEntitlement(
					productCode,
				)
			} catch (err) {
				console.error(
					'[EntitlementStore] Check failed',
					err,
				)

				return {
					allowed: false,
				}
			}
		}

		return {
			loading,
			error,
			entitlements,
			initialise,
			initialised,
			refresh,
			load,
			consume,
			getEntitlement,
			getRemainingUses,
			hasEntitlement,
			check,
		}
	},
)
