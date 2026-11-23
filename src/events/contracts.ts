/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ProductCode } from '@/constants/product'

/**
 * Typed contracts for the app-owned event bus.
 *
 * Payloads describe observed events without carrying consumer-specific
 * refresh suppression or deduplication instructions. Publishers that need
 * special refresh handling coordinate directly with the entitlement
 * coordinator, keeping the event contract consistent across consumers
 * and future event transports.
 */

// Required by mitt's emitter type constraint. The index signature does not
// loosen the explicitly declared event payload types.
export interface AppEvents extends Record<string | symbol, unknown> {
	'signing:completed': { signRequestUuid?: string; productCode?: ProductCode }
	'payment:verified': { context: 'purchase' | 'return'; productCode?: ProductCode }
	// Reserved for reservation lifecycle events; currently no publishers emit them.
	'reservation:created': { productCode?: ProductCode }
	'reservation:released': { productCode?: ProductCode }
}
