/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The signer row's reads: whether WhatsApp can reach a signer, and their signing
// link with its QR. Sending lives in useWorkflowController, because a draft has
// to be requested first.

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
}

const ids = (fileId: number, signRequestId: number) => ({ fileId, signRequestId })

export async function fetchWhatsAppRecipient(fileId: number, signRequestId: number): Promise<WhatsAppRecipient> {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/libresign/api/v1/whatsapp/{fileId}/{signRequestId}', ids(fileId, signRequestId)))
		return data.ocs.data
	} catch {
		return { available: false, masked: null, reason: 'unavailable', lastSentAt: null, availableAgainAt: null }
	}
}

export async function fetchSignLink(fileId: number, signRequestId: number): Promise<{ url: string, qr: string }> {
	const { data } = await axios.get(generateOcsUrl('/apps/libresign/api/v1/sign-link/{fileId}/{signRequestId}', ids(fileId, signRequestId)))
	return data.ocs.data
}
