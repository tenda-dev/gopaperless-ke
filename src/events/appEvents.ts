/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import mitt from 'mitt'
import type { AppEvents } from './contracts'

/**
 * App-owned event bus for UI coordination and refresh signalling.
 *
 * Kept separate from @nextcloud/event-bus to isolate GoPaperless-specific
 * events from Nextcloud's shared event infrastructure and avoid coupling
 * application refresh coordination to unrelated platform consumers.
 * Events ONLY communicate important state changes.
 */
const bus = mitt<AppEvents>()

export function emitAppEvent<K extends keyof AppEvents>(name: K, payload: AppEvents[K]): void {
	bus.emit(name, payload)
}

/**
 * Registers an isolated event listener.
 *
 * Listener failures are logged without propagating or interrupting other
 * subscribers, ensuring one faulty consumer cannot disrupt event delivery.
 *
 * @returns A function that unregisters the listener.
 */
export function onAppEvent<K extends keyof AppEvents>(
	name: K,
	handler: (payload: AppEvents[K]) => void,
): () => void {
	const safe = (payload: AppEvents[K]) => {
		try {
			handler(payload)
		} catch (err) {
			console.error(`[appEvents] ${String(name)} listener threw`, err)
		}
	}
	bus.on(name, safe)
	return () => bus.off(name, safe)
}
