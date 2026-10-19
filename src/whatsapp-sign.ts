/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Signing from the WhatsApp card (templates/whatsapp_sign.php). The server
// prepares the document and asks for SecurySign's approval; SecurySign's frame
// runs the passkey prompt on its own origin (the contract SecurySignApproval.vue
// follows); the signature it returns goes back to the server, which writes it.

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'

type Approval = { token: string, documentHash: string, documentName: string, origin: string }

const box = document.querySelector<HTMLElement>('.gp-whatsapp')
const status = document.getElementById('gp-whatsapp-status')
const holder = document.getElementById('gp-whatsapp-frame')

function say(text: string): void {
	if (status) {
		status.hidden = text === ''
		status.textContent = text
	}
}

function failed(error: unknown): void {
	const response = (error as { response?: { status: number, data?: { error?: string, message?: string } } }).response
	if (response?.status === 409) {
		// Signed elsewhere, not their turn any more, or out of credits: the page says which.
		window.location.reload()
	} else if (response?.status === 503) {
		say(t('libresign', 'SecurySign is not answering. Try again in a minute.'))
	} else {
		say(response?.data?.message || t('libresign', 'The document could not be signed. Reload this page to try again.'))
	}
}

async function sign(signature?: string): Promise<void> {
	try {
		const { data } = await axios.post(box!.dataset.signUrl!, signature ? { securysignSignature: signature } : {})
		if (data.approval) {
			approve(data.approval as Approval)
		} else if (data.signed) {
			holder?.remove()
			say('')
			document.querySelector('.gp-whatsapp__from')?.remove()
			document.querySelector('.gp-whatsapp > .gp-whatsapp__title')?.remove()
			document.getElementById('gp-whatsapp-done')!.hidden = false
		}
	} catch (error) {
		failed(error)
	}
}

function approve(approval: Approval): void {
	const frame = document.createElement('iframe')
	frame.title = t('libresign', 'Secure Signature Confirmation')
	frame.className = 'gp-whatsapp__iframe'
	frame.allow = 'publickey-credentials-get *'
	frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups')
	frame.src = `${approval.origin}/#/sign-frame?${new URLSearchParams({
		documentHash: approval.documentHash,
		documentName: approval.documentName,
		mode: 'registered',
		rpOrigin: window.location.origin,
		token: approval.token,
	})}`
	let started = false
	window.addEventListener('message', function onMessage(event: MessageEvent) {
		if (event.origin !== approval.origin || event.source !== frame.contentWindow) {
			return
		}
		const message = event.data ?? {}
		if (message.type === 'SSC_RESIZE') {
			if (message.height) {
				frame.style.height = `${message.height}px`
			}
			// Its first size report means it is listening: ask it to sign, which
			// opens the passkey prompt without a tap on the frame's own button.
			if (!started) {
				started = true
				frame.contentWindow?.postMessage({
					type: 'SSC_SIGN_REQUEST',
					documentHash: approval.documentHash,
					documentName: approval.documentName,
					mode: 'registered',
					token: approval.token,
				}, approval.origin)
			}
		} else if (message.type === 'SSC_SIGN_COMPLETE' && message.documentHash === approval.documentHash) {
			window.removeEventListener('message', onMessage)
			frame.remove()
			say(t('libresign', 'Signing…'))
			// The server checks this against the signer's certificate before writing it.
			void sign(message.signatureBase64)
		} else if (message.type === 'SSC_CLOSE_FRAME') {
			window.removeEventListener('message', onMessage)
			frame.remove()
			say(t('libresign', 'Cancelled. Reload this page to sign.'))
		}
		// SSC_SIGN_ERROR needs nothing here: the frame shows the error and a retry.
	})
	say('')
	holder?.append(frame)
}

if (box?.dataset.state === 'ready') {
	void sign()
}

// The Safari hand-off: for when WhatsApp won't open the x-safari link.
document.getElementById('gp-whatsapp-copy')?.addEventListener('click', async (event) => {
	const button = event.currentTarget as HTMLButtonElement
	try {
		await navigator.clipboard.writeText(window.location.href)
		button.textContent = t('libresign', 'Link copied. Paste it in Safari.')
	} catch {
		button.textContent = window.location.href
	}
})
