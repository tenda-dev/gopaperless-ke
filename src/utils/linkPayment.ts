/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// A payment started from a page a link opened (Seal & Deliver, the WhatsApp
// signing card): the server picks who pays and how much, this follows it to the
// end. A card goes to DPO's page and comes back to the same page, which starts
// again with the credits already bought.

import axios from '@nextcloud/axios'

type Answer = { status?: string, reference?: string, flow?: string, redirectUrl?: string | null }

// ponytail: three minutes of polling covers an STK prompt; the page reloads after.
const POLL_MS = 3000
const POLL_FOR_MS = 180000

/** SUCCESS, or the reason it isn't: FAILED, CANCELLED, EXPIRED, INITIATION_FAILED, PENDING. */
export async function payAndWait(url: string, body: Record<string, unknown>): Promise<string> {
	let answer: Answer
	try {
		answer = (await axios.post(url, body)).data
	} catch {
		return 'INITIATION_FAILED'
	}
	if (answer.status === 'SUCCESS') {
		return 'SUCCESS'
	}
	if (answer.redirectUrl && answer.flow === 'redirect') {
		window.location.assign(answer.redirectUrl)
		return new Promise(() => {})
	}
	if (!answer.reference) {
		return 'INITIATION_FAILED'
	}
	const until = Date.now() + POLL_FOR_MS
	while (Date.now() < until) {
		await new Promise((resolve) => setTimeout(resolve, POLL_MS))
		try {
			const { data } = await axios.get(`${url}/${encodeURIComponent(answer.reference)}`)
			if (data.status && data.status !== 'PENDING') {
				return data.status
			}
		} catch {
			// One lost poll is not a failed payment.
		}
	}
	return 'PENDING'
}
