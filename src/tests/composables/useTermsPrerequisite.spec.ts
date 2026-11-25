/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ enabled: true }))
const getMock = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, def: unknown) => (key === 'tos_app_enabled' ? state.enabled : def),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: getMock } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (p: string) => `/ocs/v2.php${p}` }))

import { useTermsPrerequisite } from '../../composables/useTermsPrerequisite'

const ok = (data: unknown) => ({ data: { ocs: { data } } })

describe('useTermsPrerequisite', () => {
	beforeEach(() => {
		state.enabled = true
		getMock.mockReset()
	})

	it('is not-enabled without touching the network when the app is disabled', async () => {
		state.enabled = false
		expect(await useTermsPrerequisite().resolve()).toBe('not-enabled')
		expect(getMock).not.toHaveBeenCalled()
	})

	it('is accepted when hasSigned is true', async () => {
		getMock.mockResolvedValue(ok({ terms: [{ id: 1 }], hasSigned: true }))
		expect(await useTermsPrerequisite().resolve()).toBe('accepted')
		expect(getMock).toHaveBeenCalledWith('/ocs/v2.php/apps/terms_of_service/terms')
	})

	it('is accepted when no terms apply, regardless of hasSigned', async () => {
		getMock.mockResolvedValue(ok({ terms: [] }))
		expect(await useTermsPrerequisite().resolve()).toBe('accepted')
	})

	it('is pending when hasSigned is false', async () => {
		getMock.mockResolvedValue(ok({ terms: [{ id: 1 }], hasSigned: false }))
		expect(await useTermsPrerequisite().resolve()).toBe('pending')
	})

	it.each([
		['network error', () => getMock.mockRejectedValue(new Error('offline'))],
		['HTTP 404', () => getMock.mockRejectedValue({ response: { status: 404 } })],
	])('is unknown on %s', async (_n, arrange) => {
		arrange()
		expect(await useTermsPrerequisite().resolve()).toBe('unknown')
	})

	it.each([
		['hasSigned missing', ok({ terms: [{ id: 1 }] })],
		['hasSigned not boolean', ok({ terms: [{ id: 1 }], hasSigned: 'yes' })],
		['empty body', { data: {} }],
		['terms not an array', ok({ terms: null })],
	])('is unknown on malformed response: %s', async (_n, response) => {
		getMock.mockResolvedValue(response)
		expect(await useTermsPrerequisite().resolve()).toBe('unknown')
	})
})
