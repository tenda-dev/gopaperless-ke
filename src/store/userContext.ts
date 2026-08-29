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

/**
 * Extended account state as returned by the LibreSign backend
 * (`gopaperless_extended_accounts`).
 *
 * The absence of a row is a valid business state used to preserve
 * backwards compatibility for grandfathered accounts, so all fields
 * are optional/nullable on the wire.
 */
export interface ExtendedAccount {
	createdAt: string | null
	paidCertificate: boolean
	certPaidAt: string | null
	validUntil: string | null
	isCertificateValid: boolean
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
	 * Extended (paid) account state.
	 *
	 * Sourced from the same authoritative `/account/me` response so that
	 * certificate/entitlement gating stays in sync with user identity.
	 */
	const createdAt = ref<string | null>(null)
	const paidCertificate = ref(false)
	const certPaidAt = ref<string | null>(null)
	const validUntil = ref<string | null>(null)
	// Default true = fail-open, so we never flash the paywall before
	// hydration resolves. Gating is only asserted via `certRequired`,
	// which is additionally guarded on `initialised`.
	const isCertificateValid = ref(true)

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
	 * Whether the certificate paywall should be asserted.
	 *
	 * Guarded on `initialised` so we only gate once truth is loaded —
	 * never on the fail-open pre-hydration default. This is the single
	 * source of truth the paywall modal watches; consumers must not
	 * re-derive validity from `validUntil` / `paidCertificate`.
	 */
	const certRequired = computed(() => {
		return initialised.value && !isCertificateValid.value
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
	 * Populate extended (paid) account state from backend response.
	 *
	 * Mirrors `setContext`: the server owns validity, so we copy
	 * `isCertificateValid` verbatim and never re-derive it here.
	 */
	function setExtended(extended: ExtendedAccount): void {
		paidCertificate.value = extended.paidCertificate
		certPaidAt.value = extended.certPaidAt
		validUntil.value = extended.validUntil
		isCertificateValid.value = extended.isCertificateValid
		createdAt.value = extended.createdAt
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
		const extended = response.data.ocs.data.extended

		setContext({
			uid: account.uid,
			emailAddress: account.emailAddress,
			displayName: account.displayName,
			phoneNumber: settings.phoneNumber ?? '',
		})

		setExtended(extended)
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
	 * Force a re-pull of /account/me, bypassing the initialise guard.
	 *
	 * Call after a successful cert payment so `certRequired` recomputes
	 * without a full reload. Non-destructive: a failed refresh keeps the
	 * current session/identity intact (unlike `initialise()`, which
	 * clears on failure — a transient network blip shouldn't log out).
	 */
	async function refresh(): Promise<void> {
		await hydrate()
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

		createdAt.value = null
		paidCertificate.value = false
		certPaidAt.value = null
		validUntil.value = null
		// Reset to the fail-open default, not false — a cleared store is
		// pre-hydration state, and `certRequired` stays false until
		// `initialised` flips true again on the next hydrate.
		isCertificateValid.value = true

		initialised.value = false
		loading.value = null
	}

	return {
		uid,
		emailAddress,
		displayName,
		phoneNumber,

		createdAt,
		paidCertificate,
		certPaidAt,
		validUntil,
		isCertificateValid,

		isAuthenticated,
		certRequired,
		initialised,

		setContext,
		setExtended,
		hydrate,
		initialise,
		refresh,
		clear,
	}
})
