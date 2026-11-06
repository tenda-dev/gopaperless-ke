/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// A Tuandikiane draft's review page, and the page where a signer known only by
// phone takes their slot (templates/tuandikiane.php). The review page draws the
// agreement with its boxes. Either way the action runs behind a MIMI passkey:
// MIMI's own page in a frame runs the prompt, our server fetches the challenge
// and has MIMI verify the answer, the same messages as passkey.ts.

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import * as pdfjsLib from 'pdfjs-dist'

import { canRunPasskey } from './utils/passkeyBrowser'

type Step = 'review' | 'bind' | 'opening' | 'frame' | 'signing' | 'done' | 'cancelled' | 'error' | 'browser'
type Box = { page: number, left: number, top: number, width: number, height: number, signer: string }
type Size = { w: number, h: number }

const box = document.querySelector<HTMLElement>('.gp-tk')
const holder = document.getElementById('gp-tk-frame')
const mode = box?.dataset.mode ?? ''
const frameUrl = box?.dataset.frameUrl ?? ''
const mimi = frameUrl ? new URL(frameUrl).origin : ''
let frame: HTMLIFrameElement | null = null
let slow: ReturnType<typeof setTimeout> | undefined

const SCREENS: Record<string, Partial<Record<Step, [string, string]>>> = {
	review: {
		review: [t('libresign', 'Review your agreement'), t('libresign', 'Check the details. When you tap Send, MIMI asks for your passkey, then everyone gets their signing link on WhatsApp.')],
		opening: [t('libresign', 'Review your agreement'), t('libresign', 'Opening MIMI…')],
		signing: [t('libresign', 'Sending'), t('libresign', 'Sending your agreement…')],
		done: [t('libresign', 'Sent'), t('libresign', 'Everyone gets their signing link on WhatsApp.')],
		cancelled: [t('libresign', 'Not sent'), t('libresign', 'Nothing was sent.')],
		error: [t('libresign', 'Couldn\'t send'), t('libresign', 'Your agreement was not sent. Try again.')],
	},
	bind: {
		bind: [t('libresign', 'Confirm it\'s you'), t('libresign', 'Use your MIMI passkey to link this signature to your account. Then you can sign.')],
		opening: [t('libresign', 'Confirm it\'s you'), t('libresign', 'Opening MIMI…')],
		signing: [t('libresign', 'Confirming'), t('libresign', 'Linking this signature to your account…')],
		cancelled: [t('libresign', 'Not confirmed'), t('libresign', 'Nothing changed.')],
		error: [t('libresign', 'Couldn\'t confirm'), t('libresign', 'Nothing changed. Try again.')],
	},
}
const BROWSER: [string, string] = [t('libresign', 'Open in Chrome'), t('libresign', 'This browser can\'t use your passkey. Open this page in Chrome to continue.')]

function show(step: Step, line?: string): void {
	box!.dataset.step = step
	const screen = step === 'browser' ? BROWSER : SCREENS[mode]?.[step]
	if (screen) {
		document.getElementById('gp-tk-title')!.textContent = screen[0]
		document.getElementById('gp-tk-line')!.textContent = line || screen[1]
	}
	const start = document.getElementById('gp-tk-start')
	if (start && (step === 'cancelled' || step === 'error')) {
		start.textContent = t('libresign', 'Try again')
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
	if (response?.status === 410) {
		// Sent, expired or taken meanwhile: the page says which.
		window.location.reload()
	} else if (response?.status === 503) {
		show('error', t('libresign', 'MIMI isn\'t answering. Try again in a minute.'))
	} else {
		show('error', response?.data?.message)
	}
}

async function start(): Promise<void> {
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

/** The challenge for this user's MIMI passkey, handed to MIMI's frame. */
async function ask(retry?: 'rejected'): Promise<void> {
	try {
		const { data } = await axios.post(box!.dataset.optionsUrl!)
		if (data.redirect) {
			// No MIMI passkey yet, or signed out: MIMI or the login page, then back here.
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
		const { data } = await axios.post(box!.dataset.actionUrl!, { response })
		close()
		if (data.redirect) {
			window.location.assign(data.redirect)
			return
		}
		const next = document.getElementById('gp-tk-next')
		if (data.signUrl) {
			next?.setAttribute('href', data.signUrl)
		} else {
			// The sender drew it up for others and signs nothing.
			next?.remove()
		}
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
 * top-left, as Tuandikiane sent them.
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

if (box && mode !== 'expired') {
	document.getElementById('gp-tk-start')?.addEventListener('click', () => void start())
	document.getElementById('gp-tk-copy')?.addEventListener('click', async (event) => {
		const label = (event.currentTarget as HTMLElement).querySelector('span')!
		label.textContent = await copy(window.location.href) ? t('libresign', 'Link copied') : window.location.href
	})
	drawPages().catch((error) => {
		// The link to the PDF stays in place of the pages.
		console.error('Could not draw the agreement', error)
	})
}
