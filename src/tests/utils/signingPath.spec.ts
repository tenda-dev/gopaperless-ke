/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { isSigningPath } from '../../utils/signingPath'

describe('isSigningPath', () => {
	const uuid = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'

	it('accepts a signing link, with or without index.php', () => {
		expect(isSigningPath(`/apps/libresign/p/sign/${uuid}`)).toBe(true)
		expect(isSigningPath(`/index.php/apps/libresign/p/sign/${uuid}`)).toBe(true)
	})

	it('refuses anything that could leave the signing page', () => {
		expect(isSigningPath(`//evil.example/apps/libresign/p/sign/${uuid}`)).toBe(false)
		expect(isSigningPath(`https://evil.example/apps/libresign/p/sign/${uuid}`)).toBe(false)
		expect(isSigningPath(`/apps/libresign/p/sign/${uuid}/../../settings`)).toBe(false)
		expect(isSigningPath('/apps/libresign/f/')).toBe(false)
	})
})
