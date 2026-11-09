/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Seal & Deliver (templates/tuandikiane.php), the moderator's page for a
// Tuandikiane draft. It draws the agreement with its boxes, works out what the
// moderator's credits cover, takes a payment only when they fall short, then
// seals: behind their MIMI passkey (MIMI's own page in a frame runs the prompt,
// our server fetches the challenge and has MIMI verify the answer, the same
// messages as passkey.ts), or, when they sign too, on to their signing card,
// where their signature is the seal.

import axios from '@nextcloud/axios'
import { n, t } from '@nextcloud/l10n'
import * as pdfjsLib from 'pdfjs-dist'

import { canRunPasskey } from './utils/passkeyBrowser'
import { payAndWait } from './utils/linkPayment'

type Step = 'review' | 'paying' | 'opening' | 'frame' | 'signing' | 'done' | 'cancelled' | 'error' | 'browser'
type Box = { page: number, left: number, top: number, width: number, height: number, signer: string }
type Size = { w: number, h: number }

const box = document.querySelector<HTMLElement>('.gp-tk')
const holder = document.getElementById('gp-tk-frame')
const mode = box?.dataset.mode ?? ''
const frameUrl = box?.dataset.frameUrl ?? ''
const mimi = frameUrl ? new URL(frameUrl).origin : ''
const signs = box?.dataset.signs === '1'
const signers = Number(box?.dataset.signers ?? 0)
const price = box?.dataset.price === undefined ? null : Number(box.dataset.price)
const credits = Number(box?.dataset.credits ?? 0)
const payFor = document.getElementById('gp-tk-pay-for') as HTMLInputElement | null
let frame: HTMLIFrameElement | null = null
let slow: ReturnType<typeof setTimeout> | undefined

const SCREENS: Partial<Record<Step, [string, string]>> = {
	opening: [t('libresign', 'Seal & Deliver'), t('libresign', 'Opening MIMI…')],
	signing: [t('libresign', 'Sealing'), t('libresign', 'Sealing your agreement…')],
	done: [t('libresign', 'Sealed & delivered'), t('libresign', 'Everyone who signs has their link on WhatsApp.')],
	cancelled: [t('libresign', 'Not sealed'), t('libresign', 'Nothing was sent.')],
	error: [t('libresign', 'Couldn\'t seal'), t('libresign', 'Your agreement was not sent. Try again.')],
	browser: [t('libresign', 'Open in Chrome'), t('libresign', 'This browser can\'t use your passkey. Open this page in Chrome to continue.')],
}

/** What sealing costs now: the signatures the credits don't cover, in shillings. */
function due(): { needed: number, short: number, amount: number } {
	const needed = price !== null && payFor?.checked ? signers : 0
	const short = Math.max(0, needed - credits)
	return { needed, short, amount: short * (price ?? 0) }
}

function method(): 'mobile' | 'card' {
	const picked = document.querySelector<HTMLInputElement>('input[name="gp-tk-method"]:checked')?.value
	return picked === 'card' ? 'card' : 'mobile'
}

/** The money card, the picker and the button, from the switch and the credits. */
function render(): void {
	if (price === null) {
		return
	}
	const { needed, short, amount } = due()
	document.getElementById('gp-tk-pay-line')!.textContent = payFor?.checked
		? n('libresign', '%n signature · KES {price} each', '%n signatures · KES {price} each', signers, { price })
		: t('libresign', 'Each signer pays KES {price} when they sign', { price })
	document.getElementById('gp-tk-balance')!.textContent = needed > 0
		? n('libresign', 'Your credits: %n left', 'Your credits: %n left', credits) + ' · ' + (short > 0 ? t('libresign', 'needs {needed}', { needed }) : t('libresign', 'this uses {needed}', { needed }))
		: n('libresign', 'Your credits: %n left', 'Your credits: %n left', credits)
	const badge = document.getElementById('gp-tk-badge')!
	badge.textContent = short > 0 ? t('libresign', '{short} short', { short }) : (needed > 0 ? t('libresign', 'Covered') : t('libresign', 'Nothing due'))
	badge.dataset.short = short > 0 ? '1' : ''
	document.getElementById('gp-tk-methods')!.hidden = short === 0
	document.getElementById('gp-tk-amount')!.textContent = t('libresign', 'Pay KES {amount} with', { amount })
	const card = method() === 'card'
	document.getElementById('gp-tk-phone-row')!.hidden = card
	document.getElementById('gp-tk-card-note')!.hidden = !card
	if (box?.dataset.step === 'review' || box?.dataset.step === 'cancelled' || box?.dataset.step === 'error') {
		document.getElementById('gp-tk-start')!.textContent = short > 0 ? t('libresign', 'Pay KES {amount} & seal', { amount }) : t('libresign', 'Seal')
		document.getElementById('gp-tk-line')!.textContent = short > 0
			? (card ? t('libresign', 'Card, then one touch of your passkey') : t('libresign', 'M-Pesa prompt, then one touch of your passkey'))
			: (signs ? t('libresign', 'Then your signature delivers it') : t('libresign', 'One touch of your passkey seals and delivers it'))
	}
}

function show(step: Step, line?: string): void {
	box!.dataset.step = step
	const screen = SCREENS[step]
	if (screen) {
		document.getElementById('gp-tk-title')!.textContent = screen[0]
	}
	if (line || screen) {
		document.getElementById('gp-tk-line')!.textContent = line || screen![1]
	}
	if (step === 'cancelled' || step === 'error') {
		document.getElementById('gp-tk-start')!.textContent = t('libresign', 'Try again')
	}
}

function offerBrowser(): void {
	close()
	show('browser')
	document.querySelector<HTMLElement>('.gp-whatsapp__browser')!.hidden = false
}

function close(): void {
	clearTimeout(slow)
	frame?.remove()
	frame = null
}

function status(error: unknown): number | undefined {
	return (error as { response?: { status: number } }).response?.status
}

function failed(error: unknown): void {
	close()
	const response = (error as { response?: { status: number, data?: { message?: string } } }).response
	if (response?.status === 410 || response?.status === 402) {
		// Sealed, expired, or the credits changed meanwhile: the page says which.
		window.location.reload()
	} else if (response?.status === 503) {
		show('error', t('libresign', 'MIMI isn\'t answering. Try again in a minute.'))
	} else {
		show('error', response?.data?.message)
	}
}

/** The button: pay what's short, then seal. */
async function start(): Promise<void> {
	const { short, amount } = due()
	if (short > 0) {
		const phone = (document.getElementById('gp-tk-phone') as HTMLInputElement | null)?.value ?? ''
		show('paying', method() === 'card' ? t('libresign', 'Finish on DPO\'s card page') : t('libresign', 'Enter your PIN on your phone to pay KES {amount}', { amount }))
		const paid = await payAndWait(box!.dataset.payUrl!, { payForSigners: !!payFor?.checked, method: method(), phone })
		if (paid !== 'SUCCESS') {
			show('error', paid === 'CANCELLED'
				? t('libresign', 'The payment was cancelled, so nothing was paid. Try again, or pick another way.')
				: t('libresign', 'The payment didn\'t go through, so nothing was paid. Try again, or pick another way.'))
			return
		}
	}
	await seal()
}

async function seal(): Promise<void> {
	if (signs) {
		// Their signature is the seal: on to their card, which asks for it at once.
		show('signing')
		try {
			const { data } = await axios.post(box!.dataset.actionUrl!, { payForSigners: !!payFor?.checked })
			window.location.assign(data.signUrl)
		} catch (error) {
			failed(error)
		}
		return
	}
	if (!frameUrl) {
		show('error', t('libresign', 'MIMI isn\'t answering. Try again in a minute.'))
		return
	}
	if (!await canRunPasskey()) {
		offerBrowser()
		return
	}
	close()
	show('opening')
	frame = document.createElement('iframe')
	frame.className = 'gp-whatsapp__iframe'
	frame.title = t('libresign', 'MIMI passkey check')
	frame.allow = 'publickey-credentials-get'
	frame.src = frameUrl
	holder?.append(frame)
	// ponytail: a fixed wait, as for SecurySign's frame on the WhatsApp card.
	slow = setTimeout(() => {
		if (box!.dataset.step === 'opening') {
			close()
			show('error', t('libresign', 'MIMI didn\'t open. Try again.'))
		}
	}, 20000)
}

/** The challenge for the moderator's MIMI passkey, handed to MIMI's frame. */
async function ask(retry?: 'rejected'): Promise<void> {
	try {
		const { data } = await axios.post(box!.dataset.optionsUrl!)
		if (data.redirect) {
			// No MIMI passkey yet: MIMI sets one up, then back here.
			window.location.assign(data.redirect)
			return
		}
		show('frame')
		frame?.contentWindow?.postMessage({ type: 'MIMI_PASSKEY_REQUEST', options: data.options, retry }, mimi)
	} catch (error) {
		failed(error)
	}
}

async function submit(response: unknown): Promise<void> {
	show('signing')
	try {
		await axios.post(box!.dataset.actionUrl!, { response, payForSigners: !!payFor?.checked })
		close()
		show('done')
	} catch (error) {
		if (status(error) === 403) {
			// Not this account's passkey: MIMI's frame asks again.
			await ask('rejected')
			return
		}
		failed(error)
	}
}

window.addEventListener('message', (event) => {
	if (!frame || event.origin !== mimi || event.source !== frame.contentWindow) {
		return
	}
	switch (event.data?.type) {
	case 'MIMI_PASSKEY_READY':
		clearTimeout(slow)
		void ask()
		break
	case 'MIMI_PASSKEY_RESULT':
		void submit(event.data.response)
		break
	case 'MIMI_PASSKEY_CANCEL':
		close()
		show('cancelled')
		break
	}
})

/**
 * Each page on a canvas the width of the card, each box over it in the page's own
 * proportions, labelled with its signer. The positions are PDF points from the
 * top-left, as Tuandikiane sent them. Drawn the first time the file card opens.
 */
async function drawPages(): Promise<void> {
	const pages = document.getElementById('gp-tk-pages')
	if (!pages || !box?.dataset.pdfUrl) {
		return
	}
	const sizes = JSON.parse(box.dataset.pages || '[]') as Size[]
	const boxes = JSON.parse(box.dataset.boxes || '[]') as Box[]
	const worker = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')
	pdfjsLib.GlobalWorkerOptions.workerSrc = (worker as { default: string }).default
	const pdf = await pdfjsLib.getDocument({ url: box.dataset.pdfUrl }).promise
	const width = pages.clientWidth || 320
	const ratio = Math.min(window.devicePixelRatio || 1, 2)
	pages.replaceChildren()
	for (let number = 1; number <= pdf.numPages; number++) {
		const page = await pdf.getPage(number)
		const natural = page.getViewport({ scale: 1 })
		const viewport = page.getViewport({ scale: (width / natural.width) * ratio })
		const sheet = document.createElement('div')
		sheet.className = 'gp-tk__page'
		const canvas = document.createElement('canvas')
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		sheet.append(canvas)
		pages.append(sheet)
		await page.render({ canvas, canvasContext: canvas.getContext('2d')!, viewport }).promise
		const size = sizes[number - 1] ?? { w: natural.width, h: natural.height }
		for (const mark of boxes.filter((one) => one.page === number)) {
			const outline = document.createElement('div')
			outline.className = 'gp-tk__box'
			outline.style.left = `${(mark.left / size.w) * 100}%`
			outline.style.top = `${(mark.top / size.h) * 100}%`
			outline.style.width = `${(mark.width / size.w) * 100}%`
			outline.style.height = `${(mark.height / size.h) * 100}%`
			outline.textContent = mark.signer
			sheet.append(outline)
		}
	}
}

// Android web views can refuse navigator.clipboard, and this is the only way out of
// one that won't follow the Chrome link.
async function copy(text: string): Promise<boolean> {
	try {
		await navigator.clipboard.writeText(text)
		return true
	} catch {
		const field = document.createElement('textarea')
		field.value = text
		document.body.append(field)
		field.select()
		const copied = document.execCommand('copy')
		field.remove()
		return copied
	}
}

if (box && mode === 'review') {
	render()
	payFor?.addEventListener('change', render)
	document.querySelectorAll('input[name="gp-tk-method"]').forEach((radio) => radio.addEventListener('change', render))
	document.getElementById('gp-tk-start')?.addEventListener('click', () => void start())
	document.getElementById('gp-tk-copy')?.addEventListener('click', async (event) => {
		const label = (event.currentTarget as HTMLElement).querySelector('span')!
		label.textContent = await copy(window.location.href) ? t('libresign', 'Link copied') : window.location.href
	})
	const document_ = document.querySelector<HTMLDetailsElement>('.gp-tk__document')
	document_?.addEventListener('toggle', () => {
		if (document_.open && !document_.dataset.drawn) {
			document_.dataset.drawn = '1'
			drawPages().catch((error) => {
				// The link to the PDF stays in place of the pages.
				console.error('Could not draw the agreement', error)
			})
		}
	})
}
