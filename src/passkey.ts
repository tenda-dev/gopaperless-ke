/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The MIMI passkey check after sign-in (templates/mimi_passkey.php). MIMI's own
// page in the frame runs the prompt; our server fetches the challenge and has
// MIMI verify the answer. Messages are MIMI's /passkey/frame contract.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const box = document.querySelector<HTMLElement>('.gp-passkey')
const frame = document.querySelector<HTMLIFrameElement>('#gp-passkey-frame')
const returnTo = box?.dataset.returnTo || generateUrl('/apps/libresign/')
const mimi = frame ? new URL(frame.src).origin : ''

/** Fresh options from our server, or null once it has sent the browser on. */
async function options(): Promise<unknown | null> {
	const { data } = await axios.post(generateUrl('/apps/libresign/securysign/passkey/options'))
	if (data.redirect || data.skip) {
		window.location.assign(data.redirect ?? returnTo)
		return null
	}
	return data.options
}

async function ask(retry?: 'rejected'): Promise<void> {
	// A failed call means a dead session or no network; going on lets the
	// server decide, which is the sign-in page or this one again.
	const found = await options().catch(() => {
		window.location.assign(returnTo)
		return null
	})
	if (found) {
		frame?.contentWindow?.postMessage({ type: 'MIMI_PASSKEY_REQUEST', options: found, retry }, mimi)
	}
}

async function verify(response: unknown): Promise<void> {
	try {
		await axios.post(generateUrl('/apps/libresign/securysign/passkey/verify'), { response })
	} catch {
		await ask('rejected')
		return
	}
	window.location.assign(returnTo)
}

// A background tab cannot open the passkey prompt, so wait until it is seen.
function whenVisible(run: () => void): void {
	if (document.visibilityState !== 'hidden') {
		run()
		return
	}
	const seen = () => {
		if (document.visibilityState === 'visible') {
			document.removeEventListener('visibilitychange', seen)
			run()
		}
	}
	document.addEventListener('visibilitychange', seen)
}

let asked = false
window.addEventListener('message', (event) => {
	if (!frame || event.origin !== mimi || event.source !== frame.contentWindow) {
		return
	}
	switch (event.data?.type) {
	case 'MIMI_PASSKEY_READY':
		if (!asked) {
			asked = true
			whenVisible(() => void ask())
		}
		break
	case 'MIMI_PASSKEY_RESULT':
		void verify(event.data.response)
		break
	case 'MIMI_PASSKEY_CANCEL':
		window.location.assign(box?.dataset.logoutUrl || generateUrl('/logout'))
		break
	}
})
