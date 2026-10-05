/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The MIMI passkey check after sign-in (templates/mimi_passkey.php). The prompt
// runs here under MIMI's RP ID; GoPaperless's server asks MIMI to verify it.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

type Descriptor = { id: string, type?: string, transports?: AuthenticatorTransport[] }
type Options = { challenge: string, rpId?: string, timeout?: number, userVerification?: UserVerificationRequirement, allowCredentials?: Descriptor[] }

const box = document.querySelector<HTMLElement>('.gp-passkey')
const start = document.querySelector<HTMLButtonElement>('#gp-passkey-start')
const error = document.querySelector<HTMLElement>('#gp-passkey-error')
const returnTo = box?.dataset.returnTo || generateUrl('/apps/libresign/')

const fromBase64url = (value: string) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0))
const toBase64url = (buffer: ArrayBuffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

const show = (message: string | null) => {
	if (!error) {
		return
	}
	error.hidden = message === null
	error.textContent = message ?? ''
}

async function confirm(): Promise<void> {
	const { data } = await axios.post(generateUrl('/apps/libresign/securysign/passkey/options'))
	if (data.redirect) {
		window.location.assign(data.redirect)
		return
	}
	if (data.skip) {
		window.location.assign(returnTo)
		return
	}
	const options = data.options as Options
	const credential = await navigator.credentials.get({
		publicKey: {
			challenge: fromBase64url(options.challenge),
			rpId: options.rpId,
			timeout: options.timeout,
			userVerification: options.userVerification,
			allowCredentials: (options.allowCredentials ?? []).map((c) => ({ type: 'public-key' as const, id: fromBase64url(c.id), transports: c.transports })),
		},
	}) as PublicKeyCredential | null
	if (!credential) {
		throw new Error('cancelled')
	}
	const assertion = credential.response as AuthenticatorAssertionResponse
	const response = {
		id: credential.id,
		rawId: toBase64url(credential.rawId),
		type: credential.type,
		authenticatorAttachment: credential.authenticatorAttachment ?? undefined,
		clientExtensionResults: credential.getClientExtensionResults(),
		response: {
			clientDataJSON: toBase64url(assertion.clientDataJSON),
			authenticatorData: toBase64url(assertion.authenticatorData),
			signature: toBase64url(assertion.signature),
			userHandle: assertion.userHandle ? toBase64url(assertion.userHandle) : undefined,
		},
	}
	await axios.post(generateUrl('/apps/libresign/securysign/passkey/verify'), { response })
	window.location.assign(returnTo)
}

start?.addEventListener('click', async () => {
	start.disabled = true
	show(null)
	try {
		await confirm()
	} catch (e) {
		const refused = (e as { response?: { data?: { error?: string } } }).response?.data?.error
		show(refused ?? 'The passkey prompt closed before it finished. Try again.')
		start.disabled = false
	}
})
