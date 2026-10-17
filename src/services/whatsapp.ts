/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The signer row's reads: whether WhatsApp can reach a signer, and their signing
// link with its QR. Sending lives in useWorkflowController, because a draft has
// to be requested first. The add-signer form's switch uses the account check and
// the choice; the server sends when the signer is asked to sign.

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type WhatsAppRecipient = {
	available: boolean
	/** +254 7•• ••• 678. The full number never reaches the browser. */
	masked: string | null
	/** disabled, envelope, signed, not_account, password_account, no_sub, no_number, unavailable */
	reason: string | null
	lastSentAt: string | null
	availableAgainAt: string | null
	/** Why availableAgainAt is ahead: sent (once a day), retry_wait (2 minutes after a try) or daily_limit (3 tries a day). */
	waitReason?: string | null
	/** The latest send failed, possibly after Twilio accepted it (twilio_63016, …). */
	lastError: string | null
	/** WhatsApp was turned on when the signer was added, so it goes with the request. */
	chosen?: boolean
}

export type WhatsAppAccount = Pick<WhatsAppRecipient, 'available' | 'masked' | 'reason'>

/** Kenyan mobile numbers only, as the server takes them (WhatsAppService::normalize). */
export function normalizeWhatsAppNumber(value: string): string | null {
	let digits = value.replace(/\D/g, '')
	if (digits.startsWith('254')) {
		digits = digits.slice(3)
	}
	digits = digits.replace(/^0+/, '')
	return /^[17]\d{8}$/.test(digits) ? `254${digits}` : null
}

/** The add-signer form's check, before the signer has a sign request. */
export async function fetchWhatsAppAccount(uid: string): Promise<WhatsAppAccount> {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/libresign/api/v1/whatsapp/account'), { params: { uid } })
		return data.ocs.data
	} catch {
		return { available: false, masked: null, reason: 'unavailable' }
	}
}

/** Send this signer the request on WhatsApp when they are asked to sign, to `to` if given. */
export async function chooseWhatsApp(fileId: number, signRequestId: number, to: string): Promise<void> {
	await axios.put(generateOcsUrl('/apps/libresign/api/v1/whatsapp/{fileId}/{signRequestId}/choice', ids(fileId, signRequestId)), { send: true, to })
}

const ids = (fileId: number, signRequestId: number) => ({ fileId, signRequestId })

export async function fetchWhatsAppRecipient(fileId: number, signRequestId: number): Promise<WhatsAppRecipient> {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/libresign/api/v1/whatsapp/{fileId}/{signRequestId}', ids(fileId, signRequestId)))
		return data.ocs.data
	} catch {
		return { available: false, masked: null, reason: 'unavailable', lastSentAt: null, availableAgainAt: null, lastError: null }
	}
}

export async function fetchSignLink(fileId: number, signRequestId: number): Promise<{ url: string, qr: string }> {
	const { data } = await axios.get(generateOcsUrl('/apps/libresign/api/v1/sign-link/{fileId}/{signRequestId}', ids(fileId, signRequestId)))
	return data.ocs.data
}
