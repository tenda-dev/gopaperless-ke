/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Whether this browser can run a passkey prompt, from several flags. Any one of them
// is enough, and none of them clearing a browser proves it can: an app can rewrite
// its user agent, and a web view can answer yes to every check. Whatever slips past
// is caught by the ceremony's own error (SSC_SIGN_ERROR in whatsapp-sign.ts).
//
// - The user agent names WhatsApp's browser, or any Android web view.
// - The browser's brands name Android WebView, which a rewritten user agent doesn't
//   change unless the app also overrides its client hints.
// - A web view with WebAuthn off has no PublicKeyCredential at all.
// - An iPhone app's browser answers false to the platform check.

export type BrowserAnswer = {
	ok: boolean
	inAppUserAgent: boolean
	brands: string[]
	credentialApi: boolean
	touchScreen: boolean
	platformCheck: 'yes' | 'no' | 'no answer' | 'not asked'
}

const TRY_HERE = 'gp-passkey-try-here'

// ponytail: only the tokens we've seen. Add one when a phone's Technical details show it.
const IN_APP_USER_AGENT = /\[WAiOS\/|WA4A\/|; wv\)/

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
	const inAppUserAgent = IN_APP_USER_AGENT.test(navigator.userAgent)
	const brands = (navigator as Navigator & { userAgentData?: { brands?: { brand: string }[] } })
		.userAgentData?.brands?.map(({ brand }) => brand) ?? []
	const credentialApi = typeof window.PublicKeyCredential !== 'undefined'
	const touchScreen = window.matchMedia('(pointer: coarse)').matches
	const platformCheck = credentialApi && touchScreen ? await askPlatform() : 'not asked'
	return {
		ok: triedHere() || (!inAppUserAgent && !brands.includes('Android WebView') && credentialApi && (platformCheck === 'yes' || platformCheck === 'not asked')),
		inAppUserAgent,
		brands,
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
