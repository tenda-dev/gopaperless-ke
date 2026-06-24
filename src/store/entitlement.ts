import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import {
	getCurrentEntitlement,
	consumeEntitlement,
	type ConsumeEntitlementPayload,
	type AvailableCredits,
	checkEntitlement,
} from '@/payment/entitlement'

import { DEFAULT_PRODUCT_CODE } from '@/constants/product'

export const useEntitlementStore = defineStore(
	'entitlement',
	() => {
		const entitlements = ref<
			Record<string, AvailableCredits | null>
		>({})

		const loading = ref<
			Record<string, boolean>
		>({})

		const errors = ref<
			Record<string, string | null>
		>({})

		/**
		 * Products already loaded at least once.
		 */
		const loadedProducts = ref(
			new Set<string>(),
		)

		/**
		 * Protects against duplicate
		 * concurrent initialisation calls.
		 */
		const loadingPromises = ref<
			Record<string, Promise<void>>
		>({})

		async function initialise(
			productCode = DEFAULT_PRODUCT_CODE,
		): Promise<void> {
			if (
				loadedProducts.value.has(
					productCode,
				)
			) {
				return
			}

			const existingPromise =
				loadingPromises.value[
					productCode
				]

			if (existingPromise) {
				return existingPromise
			}

			const promise = load(
				productCode,
			)
				.then(() => {
					loadedProducts.value.add(
						productCode,
					)
				})
				.finally(() => {
					delete loadingPromises.value[
						productCode
					]
				})

			loadingPromises.value[
				productCode
			] = promise

			return promise
		}

		async function load(
			productCode = DEFAULT_PRODUCT_CODE,
		): Promise<void> {
			loading.value[
				productCode
			] = true

			errors.value[
				productCode
			] = null

			try {
				entitlements.value[
					productCode
				] = await getCurrentEntitlement(
					productCode,
				)
			} catch (err: any) {
				errors.value[
					productCode
				] =
					err?.message ??
					'Failed to load entitlement'
			} finally {
				loading.value[
					productCode
				] = false
			}
		}

		async function refresh(
			productCode = DEFAULT_PRODUCT_CODE,
		): Promise<void> {
			await load(productCode)
		}

		function decrementLocally(
			productCode = DEFAULT_PRODUCT_CODE,
			amount = 1,
		): void {
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
					entitlement.remainingUses - amount,
				)
		}

		async function consume(
			payload: ConsumeEntitlementPayload,
		): Promise<boolean> {
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

				decrementLocally(
					productCode,
				)

				void refresh(
					productCode,
				)

				return true
			} catch (err) {
				console.error(
					`[EntitlementStore] ${productCode} consumption failed`,
					err,
				)

				/**
				 * Consumption failures must
				 * never break the signing flow.
				 */
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

		function isLoading(
			productCode = DEFAULT_PRODUCT_CODE,
		) {
			return computed(() =>
				loading.value[
					productCode
				] ?? false,
			)
		}

		function getError(
			productCode = DEFAULT_PRODUCT_CODE,
		) {
			return computed(() =>
				errors.value[
					productCode
				] ?? null,
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
			entitlements,

			initialise,
			load,
			refresh,
			consume,
			check,

			getEntitlement,
			getRemainingUses,
			hasEntitlement,

			isLoading,
			getError,
		}
	},
)
