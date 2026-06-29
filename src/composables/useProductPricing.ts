import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { getProductByCode, type Product } from '@/payment/product'
import { useNetworkState } from './useNetworkState'

export interface ProductPricing {
	loadingProduct: Ref<boolean>
	error: Ref<string | null>
	product: Ref<Product | null>
	unitPrice: ComputedRef<number>
	currency: ComputedRef<string | null>
	uses: ComputedRef<number>
	isReady: ComputedRef<boolean>
	reload: () => Promise<void>
}

export function useProductPricing(
	productCode: string
): ProductPricing {

	const { isNetworkError, markOffline } = useNetworkState()

	const loadingProduct = ref(false)

	const error = ref<string | null>(null)

	const product = ref<Product | null>(null)

	async function loadProduct() {
		loadingProduct.value = true
		error.value = null

		try {
			product.value =
				await getProductByCode(productCode)
		} catch (err: unknown) {

			if (isNetworkError(err)) {
				markOffline()
			}
			error.value =
				err instanceof Error
					? err.message
					: 'Failed to load pricing'
		} finally {

			loadingProduct.value = false
		}
	}

	const unitPrice = computed(() => {
		return product.value?.amount ?? 0
	})

	const currency = computed(() => {
		return product.value?.currency ?? null
	})

	const uses = computed(() => {
		return product.value?.uses ?? 0
	})

	const isReady = computed(() =>
		!loadingProduct.value &&
		product.value !== null
    )

	if (productCode) {
		void loadProduct()
	}

	return {
		loadingProduct,
		error,
		product,
		unitPrice,
		currency,
		uses,
		reload: loadProduct,
		isReady
	}
}
