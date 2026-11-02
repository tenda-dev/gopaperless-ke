/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Whether this browser can run a passkey prompt, in the browser's own words, so
// no list of apps or user agents has to keep up with WhatsApp or anyone else. A
// web view with WebAuthn off has no PublicKeyCredential at all, and an iPhone app's
// browser answers false. One that says yes and fails anyway is caught by the
// ceremony's own error (SSC_SIGN_ERROR in whatsapp-sign.ts).

export type BrowserAnswer = {
	ok: boolean
	credentialApi: boolean
	touchScreen: boolean
	platformCheck: 'yes' | 'no' | 'no answer' | 'not asked'
}

const TRY_HERE = 'gp-passkey-try-here'

// ponytail: a fixed wait, for web views whose promise never settles. Longer than a
// slow phone's first call to Play Services; it only delays those web views.
const NO_ANSWER_MS = 3000

function askPlatform(): Promise<'yes' | 'no' | 'no answer'> {
	const answer = Promise.resolve()
		.then(() => window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())
		.then((available): 'yes' | 'no' => available ? 'yes' : 'no', (): 'no' => 'no')
	const noAnswer = new Promise<'no answer'>((resolve) => setTimeout(() => resolve('no answer'), NO_ANSWER_MS))
	return Promise.race([answer, noAnswer])
}

/**
 * The platform check only counts on touch screens. On a computer "no" can still
 * mean a passkey on a phone, through the QR code.
 */
export async function askBrowser(): Promise<BrowserAnswer> {
	const credentialApi = typeof window.PublicKeyCredential !== 'undefined'
	const touchScreen = window.matchMedia('(pointer: coarse)').matches
	const platformCheck = credentialApi && touchScreen ? await askPlatform() : 'not asked'
	return {
		ok: triedHere() || (credentialApi && (platformCheck === 'yes' || platformCheck === 'not asked')),
		credentialApi,
		touchScreen,
		platformCheck,
	}
}

function triedHere(): boolean {
	try {
		return sessionStorage.getItem(TRY_HERE) === '1'
	} catch {
		return false
	}
}

/**
 * "Try here anyway": a phone with no screen lock also answers no, even in Chrome,
 * and the check must not bounce it between pages for the rest of this tab.
 */
export function tryHere(): void {
	try {
		sessionStorage.setItem(TRY_HERE, '1')
	} catch {
		// Blocked storage: the check asks again, and Copy link still gets them out.
	}
}

/** Same rule as WhatsAppSignController::isHandOffPath, which has the last word. */
export function isHandOffPath(path: string): boolean {
	return /^\/apps\/libresign\/[\w/-]+$/.test(path)
}
