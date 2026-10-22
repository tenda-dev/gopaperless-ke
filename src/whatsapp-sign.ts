/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Signing from the WhatsApp card (templates/whatsapp_sign.php). The server
// prepares the document and asks for SecurySign's approval; SecurySign's frame
// runs the passkey prompt on its own origin (the contract SecurySignApproval.vue
// follows); the signature it returns goes back to the server, which writes it.
// The page shows where it is through data-step, which login.scss draws.

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'

type Approval = { token: string, documentHash: string, documentName: string, origin: string }
type Step = 'preparing' | 'opening' | 'frame' | 'signing' | 'done' | 'cancelled' | 'error'

const box = document.querySelector<HTMLElement>('.gp-whatsapp')
const holder = document.getElementById('gp-whatsapp-frame')

const SCREENS: Partial<Record<Step, [string, string]>> = {
	preparing: [t('libresign', 'Preparing to sign'), t('libresign', 'Placing your signature on the page…')],
	opening: [t('libresign', 'Preparing to sign'), t('libresign', 'Opening SecurySign…')],
	signing: [t('libresign', 'Signing'), t('libresign', 'Applying your signature…')],
	done: [t('libresign', 'Signed'), t('libresign', 'Sending a copy to Whatsapp')],
	cancelled: [t('libresign', 'Signature cancelled'), t('libresign', 'Nothing was signed.')],
	error: [t('libresign', 'Couldn\'t sign'), t('libresign', 'The document could not be signed.')],
}

function show(step: Step, line?: string): void {
	box!.dataset.step = step
	const screen = SCREENS[step]
	if (screen) {
		document.getElementById('gp-whatsapp-title')!.textContent = screen[0]
		document.getElementById('gp-whatsapp-line')!.textContent = line || screen[1]
	}
	const retry = document.getElementById('gp-whatsapp-retry')
	if (retry) {
		retry.textContent = step === 'cancelled' ? t('libresign', 'Sign again') : t('libresign', 'Try again')
	}
}

function failed(error: unknown): void {
	const response = (error as { response?: { status: number, data?: { message?: string } } }).response
	if (response?.status === 409) {
		// Signed elsewhere, not their turn any more, or out of credits: the page says which.
		window.location.reload()
	} else if (response?.status === 503) {
		show('error', t('libresign', 'SecurySign isn\'t answering. Try again in a minute.'))
	} else {
		show('error', response?.data?.message)
	}
}

async function sign(signature?: string): Promise<void> {
	try {
		const { data } = await axios.post(box!.dataset.signUrl!, signature ? { securysignSignature: signature } : {})
		if (data.approval) {
			approve(data.approval as Approval)
		} else if (data.signed) {
			// The chat with our number; without it, whatsapp:// opens the app where they left it.
			if (data.chatUrl) {
				document.getElementById('gp-whatsapp-chat')?.setAttribute('href', data.chatUrl)
			}
			show('done')
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
	const close = (): void => {
		window.removeEventListener('message', onMessage)
		clearTimeout(slow)
		frame.remove()
	}
	// ponytail: a fixed wait; SecurySign's frame is one small page.
	const slow = setTimeout(() => {
		if (box!.dataset.step === 'opening') {
			close()
			show('error', t('libresign', 'SecurySign didn\'t open. Try again.'))
		}
	}, 20000)
	function onMessage(event: MessageEvent): void {
		if (event.origin !== approval.origin || event.source !== frame.contentWindow) {
			return
		}
		const message = event.data ?? {}
		if (message.type === 'SSC_RESIZE') {
			if (message.height) {
				frame.style.height = `${message.height}px`
			}
			// Its first size report means it has drawn and is listening: show it,
			// and ask it to sign, which opens the passkey prompt without a tap on
			// the frame's own button.
			if (box!.dataset.step === 'opening') {
				show('frame')
				frame.contentWindow?.postMessage({
					type: 'SSC_SIGN_REQUEST',
					documentHash: approval.documentHash,
					documentName: approval.documentName,
					mode: 'registered',
					token: approval.token,
				}, approval.origin)
			}
		} else if (message.type === 'SSC_SIGN_COMPLETE' && message.documentHash === approval.documentHash) {
			close()
			show('signing')
			// The server checks this against the signer's certificate before writing it.
			void sign(message.signatureBase64)
		} else if (message.type === 'SSC_CLOSE_FRAME') {
			close()
			show('cancelled')
		}
		// SSC_SIGN_ERROR needs nothing here: the frame shows the error and a retry.
	}
	window.addEventListener('message', onMessage)
	show('opening')
	holder?.append(frame)
}

function start(): void {
	show('preparing')
	void sign()
}

if (box?.dataset.step === 'preparing') {
	document.getElementById('gp-whatsapp-retry')?.addEventListener('click', start)
	start()
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
