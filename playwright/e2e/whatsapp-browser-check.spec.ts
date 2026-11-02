/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Who gets the hand-off to another browser, on the real card and login pages.
// Only SecurySign is a stand-in: its frame and the signing POST, because the real
// ones need a certificate and a real signer. The ceremony test runs a real
// navigator.credentials.get in that frame, on Chrome's virtual authenticator.
//
// Needs a stack with WhatsApp on, SecurySign set and pretty URLs, so it skips without
// a link code: GP_WHATSAPP_READY_CODE for a sign request on 'ready', and
// GP_WHATSAPP_LOGIN_CODE for one on 'login'. GP_WHATSAPP_SIGNING_ORIGIN is the
// instance's securysign_url origin.

import { generateKeyPairSync } from 'node:crypto'

import { type BrowserContext, devices, expect, type Page, test } from '@playwright/test'

const READY = process.env.GP_WHATSAPP_READY_CODE ?? ''
const LOGIN = process.env.GP_WHATSAPP_LOGIN_CODE ?? ''
const SIGNING_ORIGIN = process.env.GP_WHATSAPP_SIGNING_ORIGIN ?? 'https://securysign.com'
const TARGET = '/apps/libresign/f/validation/8c1e2f6a-3b7d-4c9e-8a21-5d6f7e8a9b0c'

const { defaultBrowserType: _android, ...android } = devices['Pixel 7']
const { defaultBrowserType: _iphone, ...iphone } = devices['iPhone 14']
const { defaultBrowserType: _desktop, ...desktop } = devices['Desktop Chrome']

test.skip(!READY, 'needs GP_WHATSAPP_READY_CODE')

const card = (page: Page) => page.locator('.gp-whatsapp')
const atHandOff = (page: Page) => expect(card(page)).toHaveAttribute('data-step', 'browser')

/** Browsers as they answer in the wild. */
const browsers = {
	missingApi: () => {
		delete (window as unknown as Record<string, unknown>).PublicKeyCredential
	},
	saysNo: () => {
		window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = async () => false
	},
	saysYes: () => {
		window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = async () => true
	},
	neverAnswers: () => {
		window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = () => new Promise(() => {})
	},
}

/**
 * SecurySign's side, counting the signing POSTs. The frame reports its size like the
 * real one, then does what it's told when asked to sign.
 */
async function standInSecurySign(context: BrowserContext, onSignRequest: string, credentialId = ''): Promise<{ posts: () => number }> {
	let posts = 0
	await context.route('**/p/whatsapp/*/sign', async (route) => {
		posts++
		const signature = route.request().postDataJSON()?.securysignSignature
		await route.fulfill({
			json: signature
				? { signed: true, chatUrl: '' }
				: { approval: { token: 'token', documentHash: 'hash', documentName: 'Lease.pdf', origin: SIGNING_ORIGIN } },
		})
	})
	await context.route(`${SIGNING_ORIGIN}/**`, (route) => route.fulfill({
		contentType: 'text/html',
		body: `<!doctype html><body>Stand-in frame<script>
			const rp = new URLSearchParams(location.hash.split('?')[1]).get('rpOrigin')
			const credentialId = ${JSON.stringify(credentialId)}
			const send = (message) => parent.postMessage(message, rp)
			addEventListener('message', async (event) => {
				if (event.data?.type === 'SSC_SIGN_REQUEST') {
					${onSignRequest}
				}
			})
			send({ type: 'SSC_RESIZE', height: 480 })
		</script></body>`,
	}))
	return { posts: () => posts }
}

const FAILS = 'send({ type: \'SSC_SIGN_ERROR\', error: \'Signing failed\' })'
const HANGS = '// Nothing comes back, as with a prompt that never opens.'
// A real ceremony, reported the way the real frame reports one.
const CEREMONY = `
	try {
		await navigator.credentials.get({ publicKey: {
			challenge: crypto.getRandomValues(new Uint8Array(32)),
			rpId: location.hostname,
			userVerification: 'required',
			allowCredentials: [{ type: 'public-key', id: Uint8Array.from(atob(credentialId), (c) => c.charCodeAt(0)) }],
		} })
		send({ type: 'SSC_SIGN_COMPLETE', documentHash: 'hash', signatureBase64: 'c2lnbmF0dXJl' })
	} catch (error) {
		send({ type: 'SSC_SIGN_ERROR', error: error.name })
	}`

/** A phone's passkey, on Chrome's virtual authenticator. */
async function virtualPasskey(page: Page, credentialId?: string): Promise<void> {
	const cdp = await page.context().newCDPSession(page)
	await cdp.send('WebAuthn.enable')
	const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
		options: {
			protocol: 'ctap2',
			transport: 'internal',
			hasResidentKey: true,
			hasUserVerification: true,
			isUserVerified: true,
			automaticPresenceSimulation: true,
		},
	})
	if (credentialId) {
		const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'P-256' })
		await cdp.send('WebAuthn.addCredential', {
			authenticatorId,
			credential: {
				credentialId,
				isResidentCredential: false,
				rpId: new URL(SIGNING_ORIGIN).hostname,
				privateKey: privateKey.export({ type: 'pkcs8', format: 'der' }).toString('base64'),
				signCount: 1,
			},
		})
	}
}

test.describe('the card, on an Android phone reading as plain Chrome', () => {
	test.use(android)

	const openCard = (page: Page) => page.goto(`/apps/libresign/p/whatsapp/${READY}`)

	test('a browser with no PublicKeyCredential gets the hand-off and prepares nothing', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.missingApi)
		await openCard(page)
		await atHandOff(page)
		const address = new URL(page.url()).host + new URL(page.url()).pathname
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveAttribute('href', `intent://${address}#Intent;scheme=https;package=com.android.chrome;end`)
		await expect(page.getByRole('link', { name: 'Use another browser' })).toHaveAttribute('href', `intent://${address}#Intent;scheme=https;end`)
		await expect(page.getByRole('button', { name: 'Copy link to paste in Chrome' })).toBeVisible()
		await page.getByText('Technical details').click()
		await expect(page.locator('#gp-whatsapp-details')).toContainText('PublicKeyCredential: missing')
		expect(securySign.posts()).toBe(0)
	})

	test('a phone whose platform check says no gets the hand-off', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysNo)
		await openCard(page)
		await atHandOff(page)
		expect(securySign.posts()).toBe(0)
	})

	test('a phone whose platform check never answers gets the hand-off', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.neverAnswers)
		await openCard(page)
		await atHandOff(page)
		await page.getByText('Technical details').click()
		await expect(page.locator('#gp-whatsapp-details')).toContainText('Platform passkey check: no answer')
		expect(securySign.posts()).toBe(0)
	})

	test('a real passkey signs, with the check and the frame both real', async ({ page, context }) => {
		const credentialId = Buffer.from('gopaperless-test-credential').toString('base64')
		await standInSecurySign(context, CEREMONY, credentialId)
		await virtualPasskey(page, credentialId)
		await openCard(page)
		await expect(card(page)).toHaveAttribute('data-step', 'done')
		await expect(page.locator('.gp-whatsapp__browser')).toBeHidden()
	})

	test('a browser that says yes and fails the real ceremony gets the hand-off, with Try again', async ({ page, context }) => {
		// A passkey for another credential id, so the prompt really rejects.
		const securySign = await standInSecurySign(context, CEREMONY, Buffer.from('a-credential-it-does-not-hold').toString('base64'))
		await virtualPasskey(page)
		await openCard(page)
		await atHandOff(page)
		await expect(page.locator('iframe')).toHaveCount(0)
		await page.getByText('Technical details').click()
		await expect(page.locator('#gp-whatsapp-details')).toContainText('Reason: frame error: NotAllowedError')
		await expect(page.locator('#gp-whatsapp-details')).toContainText('Platform passkey check: yes')

		await page.getByRole('button', { name: 'Try again' }).click()
		await expect.poll(securySign.posts).toBe(2)
		await atHandOff(page)
	})

	test('a prompt that hangs still has a way out under the frame', async ({ page, context }) => {
		await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysYes)
		await openCard(page)
		await expect(card(page)).toHaveAttribute('data-step', 'frame')
		const escape = page.getByRole('button', { name: 'Not working? Open in another browser' })
		await expect(escape).toBeVisible()
		const frameBottom = (await page.locator('iframe').boundingBox())!
		expect((await escape.boundingBox())!.y).toBeGreaterThanOrEqual(frameBottom.y + frameBottom.height)
		await escape.click()
		await atHandOff(page)
		await expect(page.locator('iframe')).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
	})

	test('Try here anyway goes on to the frame, and a phone with no lock isn\'t stuck', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysNo)
		await openCard(page)
		await atHandOff(page)
		await page.getByRole('button', { name: 'Try here anyway' }).click()
		await expect(card(page)).toHaveAttribute('data-step', 'frame')
		expect(securySign.posts()).toBe(1)
	})
})

test.describe('the card, on an iPhone', () => {
	test.use(iphone)

	test('an in-app browser that says no is offered Chrome, then Safari', async ({ page, context }) => {
		await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await atHandOff(page)
		const address = new URL(page.url()).host + new URL(page.url()).pathname
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveAttribute('href', `googlechromes://${address}`)
		await expect(page.getByRole('link', { name: 'Use another browser' })).toHaveAttribute('href', `x-safari-https://${address}`)
	})
})

test.describe('the card, on a computer', () => {
	test.use(desktop)

	test('no platform passkey isn\'t a block, because a phone can still scan the QR code', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await expect(card(page)).toHaveAttribute('data-step', 'frame')
		expect(securySign.posts()).toBe(1)
	})

	test('a failed ceremony gets the hand-off with Copy link and no phone links', async ({ page, context }) => {
		await standInSecurySign(context, FAILS)
		await page.addInitScript(browsers.saysYes)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await atHandOff(page)
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Copy link to paste in Chrome' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
	})
})

test.describe('the card\'s other screens', () => {
	test.use(android)

	test('"Sign in once" hands off a browser that can\'t, and stays for one that can', async ({ page }) => {
		test.skip(!LOGIN, 'needs GP_WHATSAPP_LOGIN_CODE')
		await page.addInitScript(browsers.saysYes)
		await page.goto(`/apps/libresign/p/whatsapp/${LOGIN}`)
		await expect(page.getByRole('link', { name: 'Open in GoPaperless' })).toBeVisible()
		await expect(card(page)).toHaveAttribute('data-step', 'login')

		const noBrowser = await page.context().newPage()
		await noBrowser.addInitScript(browsers.missingApi)
		await noBrowser.goto(`/apps/libresign/p/whatsapp/${LOGIN}`)
		await atHandOff(noBrowser)
		await expect(noBrowser.getByRole('link', { name: 'Open in GoPaperless' })).toBeHidden()
		await expect(noBrowser.getByRole('link', { name: 'Open in Chrome' })).toBeVisible()
	})

	test('a dead link stays a dead link', async ({ page }) => {
		await page.addInitScript(browsers.missingApi)
		await page.goto('/apps/libresign/p/whatsapp/not-a-code')
		await expect(card(page)).toHaveAttribute('data-step', 'gone')
	})
})

test.describe('the login page, on an Android phone reading as plain Chrome', () => {
	test.use(android)

	const stayedOnLogin = async (page: Page) => {
		await page.waitForTimeout(500)
		expect(new URL(page.url()).pathname).toBe('/login')
	}

	test('on the way to our page, a browser that can\'t gets the hand-off', async ({ page }) => {
		await page.addInitScript(browsers.missingApi)
		await page.goto(`/login?redirect_url=${TARGET}`)
		await page.waitForURL(/\/p\/open-in-browser/)
		await atHandOff(page)
		const address = new URL(page.url()).host + TARGET
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveAttribute('href', `intent://${address}#Intent;scheme=https;package=com.android.chrome;end`)
	})

	test('the receipt\'s link gets there by itself when signed out', async ({ page }) => {
		await page.addInitScript(browsers.saysNo)
		await page.goto(TARGET)
		await page.waitForURL(/\/p\/open-in-browser/)
		await atHandOff(page)
	})

	test('Try here anyway reaches the login and doesn\'t bounce back', async ({ page }) => {
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/login?redirect_url=${TARGET}`)
		await page.waitForURL(/\/p\/open-in-browser/)
		await page.getByRole('button', { name: 'Try here anyway' }).click()
		await page.waitForURL(/\/login/)
		await stayedOnLogin(page)
	})

	test('a browser that can stays on the login', async ({ page }) => {
		await page.addInitScript(browsers.saysYes)
		await page.goto(`/login?redirect_url=${TARGET}`)
		await stayedOnLogin(page)
	})

	for (const [name, search] of [
		['no target', ''],
		['another app', '?redirect_url=/apps/files/'],
		['a crafted target', '?redirect_url=/apps/libresign/x%23Intent;package=evil;end'],
	]) {
		test(`${name} is left alone`, async ({ page }) => {
			await page.addInitScript(browsers.missingApi)
			await page.goto(`/login${search}`)
			await stayedOnLogin(page)
		})
	}
})

test.describe('/p/open-in-browser', () => {
	test.use(android)

	test('Copy link lands here in Chrome, and it goes straight on', async ({ page }) => {
		await page.addInitScript(browsers.saysYes)
		await page.goto(`/apps/libresign/p/open-in-browser?to=${TARGET}`)
		await page.waitForURL((url) => !url.pathname.includes('open-in-browser'))
		expect(decodeURIComponent(page.url())).toContain(TARGET)
	})

	for (const to of ['https://evil.example/x', '//evil.example/x', '/apps/libresign/x%23Intent;package=evil;end']) {
		test(`a target of ${to} falls back to our own page`, async ({ page }) => {
			await page.addInitScript(browsers.missingApi)
			await page.goto(`/apps/libresign/p/open-in-browser?to=${to}`)
			await atHandOff(page)
			const chrome = await page.getByRole('link', { name: 'Open in Chrome' }).getAttribute('href')
			expect(chrome).toContain('/apps/libresign/f/request#Intent;scheme=https;package=com.android.chrome;end')
			expect(chrome).not.toContain('evil')
			await expect(card(page)).toHaveAttribute('data-target', /\/apps\/libresign\/f\/request$/)
		})
	}
})
