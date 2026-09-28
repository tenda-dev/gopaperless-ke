/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Loaded only on Nextcloud's /login page by LoginPageListener.

import { mdiShieldCheckOutline } from '@mdi/js'

import logoOnDark from '../img/gopaperless-logo.png'
import logoOnLight from '../img/gopaperless-logo-ink.png'
import spaceGrotesk from '../fonts/SpaceGrotesk-VariableFont_wght.ttf'

import './style/login.scss'

const body = document.body

// Follow the landing page's theme toggle when the visitor has used it. Without a
// saved choice the stylesheet falls back to the system colour scheme.
try {
	const theme = localStorage.getItem('gopaperless-landing-theme')
	if (theme === 'light' || theme === 'dark') {
		body.dataset.gpTheme = theme
	}
} catch {
	// Blocked storage just means the system colour scheme decides.
}

// Asset URLs come from imports rather than url() in the stylesheet: a Windows
// build writes those url() paths with encoded backslashes, which 404.
body.style.setProperty('--gp-logo-light', `url("${logoOnLight}")`)
body.style.setProperty('--gp-logo-dark', `url("${logoOnDark}")`)

const shield = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiShieldCheckOutline}"/></svg>`
body.style.setProperty('--gp-shield', `url("data:image/svg+xml,${encodeURIComponent(shield)}")`)

new FontFace('Space Grotesk', `url("${spaceGrotesk}")`, { weight: '300 700' })
	.load()
	.then((face) => document.fonts.add(face))
	.catch(() => {
		// The fallback system fonts in --font-face take over.
	})

// Ways back to the landing page. Nextcloud's guest header logo is a plain div,
// and the card offers no exit, so someone who lands here by mistake is stuck.
const webroot = (window as unknown as { OC?: { getRootPath?: () => string } }).OC?.getRootPath?.() ?? ''
const landing = `${webroot}/apps/libresign/p/upload`

const logo = document.querySelector('#header .logo')
if (logo?.parentElement) {
	const link = document.createElement('a')
	link.href = landing
	link.className = 'gp-home'
	link.setAttribute('aria-label', 'GoPaperless home')
	logo.parentElement.insertBefore(link, logo)
	link.appendChild(logo)
}

const card = document.querySelector('.guest-box.login-box')
if (card?.parentElement) {
	const back = document.createElement('a')
	back.href = landing
	back.className = 'gp-back'
	back.textContent = 'Back to GoPaperless'
	card.parentElement.appendChild(back)
}
