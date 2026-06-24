import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface UserContext {
	uid: string
	emailAddress: string
	displayName: string
	phoneNumber: string
}

export const useUserContextStore = defineStore('userContext', () => {
	/**
	 * Authenticated user identity.
	 *
	 * This is considered the authoritative source of user data
	 * within the GoPaperless payment/signing flow.
	 */
	const uid = ref('')
	const emailAddress = ref('')
	const displayName = ref('')
	const phoneNumber = ref('')

	/**
	 * Guards against repeated hydration calls.
	 *
	 * Example:
	 * - App.vue calls initialise()
	 * - Sign.vue calls initialise()
	 * - Payment modal calls initialise()
	 *
	 * Only a single request should be made.
	 */
	const initialised = ref(false)
	const loading = ref<Promise<void> | null>(null)

	/**
	 * User is considered authenticated when a UID exists.
	 */
	const isAuthenticated = computed(() => {
		return !!uid.value
	})

	/**
	 * Populate store state from backend response.
	 */
	function setContext(context: UserContext): void {
		uid.value = context.uid
		emailAddress.value = context.emailAddress
		displayName.value = context.displayName
		phoneNumber.value = context.phoneNumber
	}

	/**
	 * Fetch the latest user information from LibreSign.
	 *
	 * Uses the authoritative `/account/me` endpoint
	 * rather than relying on cached browser state.
	 */
	async function hydrate(): Promise<void> {
		const response = await axios.get(
			generateOcsUrl('/apps/libresign/api/v1/account/me'),
		)

		const account = response.data.ocs.data.account
		const settings = response.data.ocs.data.settings

		setContext({
			uid: account.uid,
			emailAddress: account.emailAddress,
			displayName: account.displayName,
			phoneNumber: settings.phoneNumber ?? '',
		})
	}

	/**
	 * Initialise user context.
	 *
	 *
	 * Concurrent callers will reuse the same
	 * in-flight request.
	 */
	async function initialise(): Promise<void> {
		if (initialised.value) {
			return
		}

		if (loading.value) {
			return loading.value
		}

		loading.value = hydrate()
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
	 * Reset all user state.
	 *
	 * Useful for:
	 * - logout
	 * - session expiration
	 * - failed hydration recovery
	 */
	function clear(): void {
		uid.value = ''
		emailAddress.value = ''
		displayName.value = ''
		phoneNumber.value = ''

		initialised.value = false
		loading.value = null
	}

	return {
		uid,
		emailAddress,
		displayName,
		phoneNumber,

		isAuthenticated,
		initialised,

		setContext,
		hydrate,
		initialise,
		clear,
	}
})
