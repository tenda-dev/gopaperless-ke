/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import {
	getCurrentSigner,
	getCurrentSignerSignRequestUuid,
	getSigningRouteUuid,
	getValidationRouteUuid,
} from '../../utils/signRequestUuid.ts'

describe('signRequestUuid utils', () => {
	it('returns only the current signer when resolving signer context', () => {
		expect(getCurrentSigner({
			signers: [
				{ sign_request_uuid: 'other-uuid' },
				{ me: true, sign_request_uuid: 'current-uuid' },
			],
		})).toEqual({ me: true, sign_request_uuid: 'current-uuid' })
	})

	it('does not fall back to the first signer when there is no current signer', () => {
		expect(getCurrentSignerSignRequestUuid({
			signers: [{ sign_request_uuid: 'other-uuid' }],
		})).toBeNull()
	})

	it('uses the provided fallback only when no current signer uuid exists', () => {
		expect(getCurrentSignerSignRequestUuid(undefined, 'fallback-uuid')).toBe('fallback-uuid')
	})

	it('prefers the current signer sign_request_uuid for approver-capable routes', () => {
		expect(getSigningRouteUuid({
			uuid: 'file-uuid',
			settings: { isApprover: true },
			signers: [{ me: true, sign_request_uuid: 'sign-request-uuid' }],
		})).toBe('sign-request-uuid')
	})

	it('falls back to file uuid for approver signing routes when signer uuid is unavailable', () => {
		expect(getSigningRouteUuid({
			uuid: 'file-uuid',
			settings: { isApprover: true },
			signers: [],
		})).toBe('file-uuid')
	})

	it('returns the current signer sign_request_uuid for regular signer routes', () => {
		expect(getSigningRouteUuid({
			uuid: 'file-uuid',
			signers: [{ me: true, sign_request_uuid: 'sign-request-uuid' }],
		})).toBe('sign-request-uuid')
	})

	it('returns the file uuid for validation routes', () => {
		expect(getValidationRouteUuid({
			uuid: 'file-uuid',
			signers: [{ me: true, sign_request_uuid: 'sign-request-uuid' }],
		})).toBe('file-uuid')
	})

	it('falls back to numeric id for validation routes when uuid is unavailable', () => {
		expect(getValidationRouteUuid({ id: 42 })).toBe(42)
	})

	it('resolves the signer sign_uuid field (actual backend contract)', () => {
		expect(getSigningRouteUuid({
			signers: [{ me: true, sign_uuid: 'backend-sign-uuid' }],
		})).toBe('backend-sign-uuid')
	})

	it('resolves the signer signUuid field', () => {
		expect(getSigningRouteUuid({
			signers: [{ me: true, signUuid: 'camel-sign-uuid' }],
		})).toBe('camel-sign-uuid')
	})

	it('prefers sign_request_uuid, then sign_uuid, then signUuid', () => {
		expect(getCurrentSignerSignRequestUuid({
			signers: [{ me: true, sign_request_uuid: 'a', sign_uuid: 'b', signUuid: 'c' }],
		})).toBe('a')
		expect(getCurrentSignerSignRequestUuid({
			signers: [{ me: true, sign_uuid: 'b', signUuid: 'c' }],
		})).toBe('b')
		expect(getCurrentSignerSignRequestUuid({
			signers: [{ me: true, signUuid: 'c' }],
		})).toBe('c')
	})

	it('returns null for a normal signer with no supported uuid', () => {
		expect(getSigningRouteUuid({
			settings: { isApprover: false },
			signers: [{ me: true }],
		})).toBeNull()
	})

	it('does not fall back to the document uuid for a non-approver', () => {
		expect(getSigningRouteUuid({
			uuid: 'file-uuid',
			settings: { isApprover: false },
			signers: [{ me: true }],
		})).toBeNull()
	})

	it('still resolves the document uuid for an approver without a signer uuid', () => {
		expect(getSigningRouteUuid({
			uuid: 'file-uuid',
			settings: { isApprover: true },
			signers: [{ me: true }],
		})).toBe('file-uuid')
	})
})
