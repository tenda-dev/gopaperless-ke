/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Whether this browser can run a passkey prompt, from several flags. Any one of them
// is enough, and none of them clearing a browser proves it can: an app can rewrite
// its user agent, and a web view can answer yes to every check. Whatever slips past
// is caught by the ceremony's own error (SSC_SIGN_ERROR in whatsapp-sign.ts).
//
// The card doesn't wait for this when the user agent already gives the browser away:
// WhatsAppSignController::isInAppBrowser answers before any script runs.
//
// - The user agent names WhatsApp's browser, or any Android web view.
// - The browser's brands name Android WebView, which a rewritten user agent doesn't
//   change unless the app also overrides its client hints.
// - A web view with WebAuthn off has no PublicKeyCredential at all.
// - An iPhone app's browser answers false to the platform check.

// Same tokens as WhatsAppSignController::isInAppBrowser. The login page needs them here.
const IN_APP_USER_AGENT = /\[WAiOS\/|WA4A\/|; wv\)/

// ponytail: a fixed wait, for web views whose promise never settles. Longer than a
// slow phone's first call to Play Services; it only delays those web views.
const NO_ANSWER_MS = 3000

function brands(): string[] {
	return (navigator as Navigator & { userAgentData?: { brands?: { brand: string }[] } })
		.userAgentData?.brands?.map(({ brand }) => brand) ?? []
}

function askPlatform(): Promise<boolean> {
	const answer = Promise.resolve()
		.then(() => window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())
		.catch(() => false)
	const noAnswer = new Promise<boolean>((resolve) => setTimeout(() => resolve(false), NO_ANSWER_MS))
	return Promise.race([answer, noAnswer])
}

/** Chrome itself, where the hand-off leads, so nothing sends anyone on from here. */
export function isChrome(): boolean {
	return brands().includes('Google Chrome') || navigator.userAgent.includes('CriOS/')
}

/**
 * The platform check only counts on a touch screen outside Chrome. On a computer "no"
 * can still mean a passkey on a phone, through the QR code. In Chrome "no" means a
 * phone with no screen lock, which no other browser would fix.
 */
export async function canRunPasskey(): Promise<boolean> {
	if (IN_APP_USER_AGENT.test(navigator.userAgent)
		|| brands().includes('Android WebView')
		|| typeof window.PublicKeyCredential === 'undefined') {
		return false
	}
	if (isChrome() || !window.matchMedia('(pointer: coarse)').matches) {
		return true
	}
	return askPlatform()
}

/** Same rule as WhatsAppSignController::isHandOffPath, which has the last word. */
export function isHandOffPath(path: string): boolean {
	return /^\/apps\/libresign\/[\w/-]+$/.test(path)
}
