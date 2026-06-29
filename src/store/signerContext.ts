import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface SignerContext {
	signRequestId: number
	signUuid: string
	email: string | null
}

export const useSignerContextStore = defineStore('signerContext', () => {
	/**
	 * Current signer identity for the active signing session.
	 *
	 * This is the authoritative signer context returned
	 * by the backend and should never be derived from
	 * frontend state.
	 */
	const signRequestId = ref<number>(0)
	const signUuid = ref<string>('')
	const email = ref<string | null>(null)

	/**
	 * Tracks the currently hydrated document UUID.
	 *
	 * Prevents unnecessary reloads when multiple
	 * components request signer context for the
	 * same document.
	 */
	const currentFileUuid = ref<string>('')

	const initialised = ref(false)
	const loading = ref<Promise<void> | null>(null)

	/**
	 * Signer context is considered ready once
	 * both identifiers required by payment/signing
	 * flows are available.
	 */
	const isReady = computed(() => {
		return !!(
			signRequestId.value &&
			signUuid.value
		)
	})

	function setContext(context: SignerContext): void {
		signRequestId.value = context.signRequestId
		signUuid.value = context.signUuid
		email.value = context.email
	}

	/**
	 * Fetch signer context from the backend.
	 *
	 * Uses the dedicated signer context endpoint
	 * rather than relying on client-side state.
	 */
	async function hydrate(uuid: string): Promise<void> {
		const response = await axios.get(
			generateOcsUrl(
				'/apps/libresign/api/v1/file/context/{uuid}',
				{ uuid },
			),
		)

		setContext(response.data.ocs.data)
		currentFileUuid.value = uuid
	}

	/**
	 * Initialise signer context.
	 *
	 * Safe to call from:
	 * - Sign.vue
	 * - PaymentModal
	 * - Entitlement flows
	 *
	 * Multiple callers will share the same
	 * in-flight request.
	 */
	async function initialise(uuid: string): Promise<void> {
		/**
		 * Already initialised for this document.
		 */
		if (
			initialised.value &&
			currentFileUuid.value === uuid
		) {
			return
		}

		if (loading.value) {
			return loading.value
		}

		loading.value = hydrate(uuid)
			.then(() => {
				initialised.value = true
			})
			.catch((error) => {
				clear()
				throw error
			})
			.finally(() => {
				loading.value = null
			})

		return loading.value
	}

	/**
	 * Reset signer context.
	 *
	 * Useful when:
	 * - navigating between documents
	 * - session recovery
	 * - failed hydration
	 */
	function clear(): void {
		signRequestId.value = 0
		signUuid.value = ''
		email.value = null

		currentFileUuid.value = ''

		initialised.value = false
		loading.value = null
	}

	return {
		signRequestId,
		signUuid,
		email,

		currentFileUuid,

		isReady,
		initialised,

		setContext,
		hydrate,
		initialise,
		clear,
	}
})
