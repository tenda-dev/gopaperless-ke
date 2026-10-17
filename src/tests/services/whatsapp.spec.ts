/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { normalizeWhatsAppNumber } from '../../services/whatsapp'

describe('normalizeWhatsAppNumber', () => {
	it('takes Kenyan mobile numbers in the forms people type them', () => {
		expect(normalizeWhatsAppNumber('0712 345 678')).toBe('254712345678')
		expect(normalizeWhatsAppNumber('+254 712-345-678')).toBe('254712345678')
		expect(normalizeWhatsAppNumber('712345678')).toBe('254712345678')
		expect(normalizeWhatsAppNumber('0110345678')).toBe('254110345678')
	})

	it('refuses landlines, other countries and fragments', () => {
		expect(normalizeWhatsAppNumber('0201234567')).toBeNull()
		expect(normalizeWhatsAppNumber('+1 415 555 0100')).toBeNull()
		expect(normalizeWhatsAppNumber('0712')).toBeNull()
		expect(normalizeWhatsAppNumber('')).toBeNull()
	})
})
