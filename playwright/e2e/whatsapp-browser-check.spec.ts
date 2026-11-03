/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Who gets the hand-off to Chrome, on the real card and login pages.
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
/** Every button and link on the card that a person can see, so nothing extra creeps back. */
const actions = (page: Page) => card(page).locator('a:visible, button:visible')

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
	brandedWebView: () => {
		Object.defineProperty(navigator, 'userAgentData', { value: { brands: [{ brand: 'Chromium', version: '141' }, { brand: 'Android WebView', version: '141' }] } })
	},
	brandedChrome: () => {
		Object.defineProperty(navigator, 'userAgentData', { value: { brands: [{ brand: 'Chromium', version: '141' }, { brand: 'Google Chrome', version: '141' }] } })
	},
	// The browser running these tests is Chrome, whatever the device it plays, so a test
	// about any other browser has to say so.
	notChrome: () => {
		Object.defineProperty(navigator, 'userAgentData', { value: { brands: [{ brand: 'Chromium', version: '141' }, { brand: 'Samsung Internet', version: '141' }] } })
	},
	// As on an iPhone, where no browser has this API.
	noBrands: () => {
		Object.defineProperty(navigator, 'userAgentData', { value: undefined })
	},
}

const WHATSAPP_ANDROID = 'Mozilla/5.0 (Linux; Android 12; SM-G990U3 Build/SP1A.210812.016) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/156.0.8078.47 Mobile Safari/537.36 WA4A/2.26.42.74'
const ANDROID_WEB_VIEW = 'Mozilla/5.0 (Linux; Android 14; SM-A546E; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.0.0 Mobile Safari/537.36'
const WHATSAPP_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1 [WAiOS/2.25.31]'

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

	test('a browser with no PublicKeyCredential gets one primary and one secondary, and prepares nothing', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.missingApi)
		await openCard(page)
		await atHandOff(page)
		await expect(actions(page)).toHaveText(['Open in Chrome', 'Copy link'])
		const address = new URL(page.url()).host + new URL(page.url()).pathname
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveAttribute('href', `intent://${address}#Intent;scheme=https;package=com.android.chrome;end`)
		await expect(page.locator('#gp-whatsapp-copy svg')).toBeVisible()
		expect(securySign.posts()).toBe(0)
	})

	test('a phone whose platform check says no gets the hand-off', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.notChrome)
		await page.addInitScript(browsers.saysNo)
		await openCard(page)
		await atHandOff(page)
		expect(securySign.posts()).toBe(0)
	})

	test('a phone whose platform check never answers gets the hand-off', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.notChrome)
		await page.addInitScript(browsers.neverAnswers)
		await openCard(page)
		await atHandOff(page)
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

	test('a browser that says yes and fails the real ceremony gets the hand-off', async ({ page, context }) => {
		// A passkey for another credential id, so the prompt really rejects.
		await standInSecurySign(context, CEREMONY, Buffer.from('a-credential-it-does-not-hold').toString('base64'))
		await virtualPasskey(page)
		await page.addInitScript(browsers.notChrome)
		await openCard(page)
		await atHandOff(page)
		await expect(page.locator('iframe')).toHaveCount(0)
		await expect(actions(page)).toHaveText(['Open in Chrome', 'Copy link'])
	})

	test('nothing extra shows while signing, even with an old stylesheet', async ({ page, context }) => {
		await context.route('**/libresign-login.css*', (route) => route.fulfill({ contentType: 'text/css', body: '' }))
		await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysYes)
		await openCard(page)
		await expect(card(page)).toHaveAttribute('data-step', 'frame')
		await expect(page.locator('.gp-whatsapp__browser')).toBeHidden()
		await expect(page.getByText('Not working?')).toHaveCount(0)
	})
})

test.describe('in Chrome itself, where the hand-off leads', () => {
	test.use(android)

	test('a phone with no screen lock goes on to the frame instead of being sent to Chrome again', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.brandedChrome)
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await expect(card(page)).toHaveAttribute('data-step', 'frame')
		expect(securySign.posts()).toBe(1)
	})

	test('a failed ceremony offers Try again, not Chrome', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, FAILS)
		await page.addInitScript(browsers.brandedChrome)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await expect(card(page)).toHaveAttribute('data-step', 'error')
		await expect(page.locator('iframe')).toHaveCount(0)
		await expect(page.locator('.gp-whatsapp__browser')).toBeHidden()
		await page.getByRole('button', { name: 'Try again' }).click()
		await expect.poll(securySign.posts).toBe(2)
	})
})

// Each flag alone is enough, with every other one saying this browser is fine.
for (const [name, device, userAgent] of [
	['WA4A/', android, WHATSAPP_ANDROID],
	['; wv)', android, ANDROID_WEB_VIEW],
	['[WAiOS/', iphone, WHATSAPP_IPHONE],
] as const) {
	test.describe(`a user agent with ${name}`, () => {
		test.use({ ...device, userAgent })

		test('opens on the hand-off and prepares nothing', async ({ page, context }) => {
			const securySign = await standInSecurySign(context, HANGS)
			await page.addInitScript(browsers.saysYes)
			await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
			await atHandOff(page)
			await expect(actions(page)).toHaveText(['Open in Chrome', 'Copy link'])
			await page.waitForTimeout(500)
			await expect(page.locator('iframe')).toHaveCount(0)
			expect(securySign.posts()).toBe(0)
		})

		test('a phone holding the old script can\'t start signing', async ({ page, context }) => {
			// What #59's script did: start on 'preparing', and go straight to the frame.
			await context.route('**/libresign-whatsapp-sign.mjs*', (route) => route.fulfill({
				contentType: 'text/javascript',
				body: `const box = document.querySelector('.gp-whatsapp')
					if (box?.dataset.step === 'preparing') { fetch(box.dataset.signUrl, { method: 'POST' }) }`,
			}))
			await context.route('**/libresign-login.css*', (route) => route.fulfill({ contentType: 'text/css', body: '' }))
			const securySign = await standInSecurySign(context, HANGS)
			await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
			await atHandOff(page)
			await expect(page.getByRole('link', { name: 'Open in Chrome' })).toBeVisible()
			await page.waitForTimeout(500)
			expect(securySign.posts()).toBe(0)
		})

		test('gets the hand-off at the login', async ({ page }) => {
			await page.addInitScript(browsers.saysYes)
			await page.goto(`/login?redirect_url=${TARGET}`)
			await page.waitForURL(/\/p\/open-in-browser/)
			await atHandOff(page)
		})

		test.describe('with no script at all', () => {
			test.use({ javaScriptEnabled: false })

			test('the card opens on the hand-off', async ({ page }) => {
				await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
				await atHandOff(page)
				await expect(page.getByRole('link', { name: 'Open in Chrome' })).toBeVisible()
				await expect(page.getByRole('heading', { name: 'Open in Chrome' })).toBeVisible()
			})

			test('/p/open-in-browser opens on the hand-off', async ({ page }) => {
				await page.goto(`/apps/libresign/p/open-in-browser?to=${TARGET}`)
				await atHandOff(page)
				await expect(page.getByRole('link', { name: 'Open in Chrome' })).toBeVisible()
			})
		})
	})
}

test.describe('the Android WebView brand, under a user agent reading as plain Chrome', () => {
	test.use(android)

	test('gets the hand-off, before anything is prepared', async ({ page, context }) => {
		const securySign = await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.saysYes)
		await page.addInitScript(browsers.brandedWebView)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await atHandOff(page)
		expect(securySign.posts()).toBe(0)
	})
})

test.describe('the card, on an iPhone', () => {
	test.use(iphone)

	test('an in-app browser that says no is offered Chrome and Copy link', async ({ page, context }) => {
		await standInSecurySign(context, HANGS)
		await page.addInitScript(browsers.noBrands)
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await atHandOff(page)
		await expect(actions(page)).toHaveText(['Open in Chrome', 'Copy link'])
		const address = new URL(page.url()).host + new URL(page.url()).pathname
		await expect(page.getByRole('link', { name: 'Open in Chrome' })).toHaveAttribute('href', `googlechromes://${address}`)
	})

	test('Chrome on iPhone isn\'t sent to Chrome again', async ({ browser }) => {
		const context = await browser.newContext({
			...iphone,
			userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1',
		})
		const page = await context.newPage()
		await standInSecurySign(context, FAILS)
		await page.addInitScript(browsers.noBrands)
		await page.addInitScript(browsers.saysNo)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await expect(card(page)).toHaveAttribute('data-step', 'error')
		await context.close()
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

	test('a failed ceremony gets Copy link as the one primary', async ({ page, context }) => {
		await standInSecurySign(context, FAILS)
		await page.addInitScript(browsers.notChrome)
		await page.addInitScript(browsers.saysYes)
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await atHandOff(page)
		await expect(actions(page)).toHaveText(['Copy link'])
		await expect(page.locator('#gp-whatsapp-copy')).not.toHaveClass(/gp-whatsapp__button--quiet/)
	})
})

test.describe('Copy link', () => {
	test.use({ ...android, userAgent: WHATSAPP_ANDROID, permissions: ['clipboard-read', 'clipboard-write'] })

	const copied = (page: Page) => page.evaluate(() => navigator.clipboard.readText())

	test('copies the page\'s address', async ({ page }) => {
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await page.getByRole('button', { name: 'Copy link' }).click()
		await expect(page.getByRole('button', { name: 'Link copied' })).toBeVisible()
		expect(await copied(page)).toBe(page.url())
	})

	test('still copies in a web view that refuses the clipboard API', async ({ page }) => {
		await page.addInitScript(() => {
			navigator.clipboard.writeText = () => Promise.reject(new DOMException('Write permission denied', 'NotAllowedError'))
		})
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await page.getByRole('button', { name: 'Copy link' }).click()
		await expect(page.getByRole('button', { name: 'Link copied' })).toBeVisible()
		expect(await copied(page)).toBe(page.url())
	})

	test('shows the address when nothing can copy it', async ({ page }) => {
		await page.addInitScript(() => {
			navigator.clipboard.writeText = () => Promise.reject(new DOMException('Write permission denied', 'NotAllowedError'))
			document.execCommand = () => false
		})
		await page.goto(`/apps/libresign/p/whatsapp/${READY}`)
		await page.locator('#gp-whatsapp-copy').click()
		await expect(page.locator('#gp-whatsapp-copy span')).toHaveText(page.url())
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
		await expect(actions(noBrowser)).toHaveText(['Open in Chrome', 'Copy link'])
	})

	test('a dead link stays a dead link', async ({ page }) => {
		await page.addInitScript(browsers.missingApi)
		await page.goto('/apps/libresign/p/whatsapp/not-a-code')
		await expect(card(page)).toHaveAttribute('data-step', 'gone')
	})
})

test.describe('a dead link in WhatsApp\'s browser', () => {
	test.use({ ...android, userAgent: WHATSAPP_ANDROID })

	test('stays a dead link', async ({ page }) => {
		await page.goto('/apps/libresign/p/whatsapp/not-a-code')
		await expect(card(page)).toHaveAttribute('data-step', 'gone')
		await expect(actions(page)).toHaveCount(0)
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
		await page.addInitScript(browsers.notChrome)
		await page.addInitScript(browsers.saysNo)
		await page.goto(TARGET)
		await page.waitForURL(/\/p\/open-in-browser/)
		await atHandOff(page)
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
