/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface AdminUser {
	uid: string
	displayName: string
	email: string | null
	enabled: boolean
}

export interface ReservationDetail {
	signRequestId: number
	quantity: number
	createdAt: string | null
	/** True when the owner is sponsoring another signer (a REQUESTER sponsorship). */
	sponsored: boolean
	/** Display name of the sponsored signer, when resolvable. */
	signerName: string | null
}

export interface EntitlementRow {
	id: number
	type: string
	remainingUses: number | null
	reservedUses: number | null
	availableUses: number | null
	expiresAt: string | null
	createdAt: string | null
	usable: boolean
	/** Active reservation detail, present only for rows with reserved uses. */
	reservations?: ReservationDetail[]
}

export interface EntitlementSummary {
	productCode: string
	availableUses: number
	remainingUses: number
	reservedUses: number
	activeEntitlements: number
	/** The entitlement the backend selector would consume next (priority + FIFO), or null. */
	nextEntitlementId: number | null
}

export interface PaymentRow {
	id: number
	createdAt: string | null
	paidAt: string | null
	status: string
	purpose: string
	provider: string
	amount: number
	currency: string
	displayAmount: number | null
	displayCurrency: string | null
	providerReference: string | null
}

export interface AccountOverview {
	user: AdminUser
	product: string
	summary: EntitlementSummary
	entitlements: EntitlementRow[]
	payments: PaymentRow[]
}

export interface GrantPayload {
	uid: string
	uses: number
	reason?: string
	reference?: string
}

export interface GrantResult {
	success: boolean
	entitlement: {
		id: number
		productCode: string
		type: string
		remainingUses: number | null
		createdAt: string | null
	}
	summary: EntitlementSummary
}


export interface LifecycleStage {
	recorded: boolean
	fields: Record<string, string>
}

export interface PaymentDetail {
	summary: {
		id: number
		attemptId: string
		status: string
		purpose: string
		provider: string
		productCode: string
		productUses: number
		quantity: number
		amount: number
		unitAmount: number
		currency: string
		displayAmount: number | null
		displayCurrency: string | null
		createdAt: string | null
		updatedAt: string | null
		paidAt: string | null
		expiresAt: string | null
		phoneRegion: string | null
		phoneCountry: string | null
		phoneMasked: string | null
		phone: string | null
	}
	outcome: {
		kind: 'success' | 'failure'
		severity: 'ok' | 'warn' | 'error'
		reason: string | null
		resultCode: number | null
		resultDesc: string | null
		source: string | null
		stageFailed: 'initiation' | 'charge' | 'callback' | 'query' | null
	}
	identifiers: {
		transactionId: number | null
		transactionReference: string | null
		providerReference: string | null
		preferredProvider: string | null
		executedProvider: string | null
		checkoutRequestId: string | null
		merchantRequestId: string | null
		dpoTransactionToken: string | null
		dpoTransactionRef: string | null
		mpesaReceipt: string | null
	}
	diagnostic: {
		executionState: string | null
		stateMismatch: boolean
		alreadyCharged: boolean
		failureCategory: string | null
		providerError: {
			type: string | null
			reason: string | null
			source: string | null
			resultCode: number | null
			resultDesc: string | null
			timestamp: string | null
			message: string | null
			exception: string | null
			code: string | null
		} | null
		legacyError: { code: string | null, message: string | null, at: string | null } | null
		hasError: boolean
	}
	lifecycle: Record<'initiation' | 'charge' | 'callback' | 'query' | 'verification', LifecycleStage | null>
	verification: {
		status: string | null
		lastCheckedAt: string | null
		retryCount: number
		nextVerificationAt: string | null
		lockedAt: string | null
	}
	raw: Record<string, unknown> | null
	row: Record<string, unknown> | null
	finalState: { status: string, allocationConfirmable: boolean, note: string }
}

const BASE = '/apps/libresign/api/v1/admin/entitlements'

/**
 * Thin client for the admin entitlement endpoints. Centralises URL building and
 * OCS envelope/error unwrapping so views and components stay declarative.
 */
export function useAdminEntitlements() {
	async function searchUsers(search: string): Promise<AdminUser[]> {
		const { data } = await axios.get(
			generateOcsUrl(BASE + '/users/search'),
			{ params: { search } },
		)
		return data?.ocs?.data?.users ?? []
	}

	async function getOverview(uid: string): Promise<AccountOverview> {
		const { data } = await axios.get(
			generateOcsUrl(BASE + '/users/{uid}/overview', { uid }),
		)
		return data?.ocs?.data as AccountOverview
	}

	async function grant(payload: GrantPayload): Promise<GrantResult> {
		const { data } = await axios.post(
			generateOcsUrl(BASE + '/grant'),
			payload,
		)
		return data?.ocs?.data as GrantResult
	}

	async function getPaymentDetail(id: number): Promise<PaymentDetail> {
		const { data } = await axios.get(
			generateOcsUrl(BASE + '/payments/{id}', { id }),
		)
		return data?.ocs?.data?.payment as PaymentDetail
	}

	return { searchUsers, getOverview, grant, getPaymentDetail }
}

/**
 * True when the request produced a definite backend response (so its outcome is
 * known). An ambiguous transport failure (no response) returns false: callers
 * must NOT assume success and should re-read authoritative state instead.
 */
export function hasDefiniteResponse(err: unknown): boolean {
	return Boolean((err as { response?: unknown })?.response)
}

export function ocsError(err: unknown, fallback: string): string {
	const e = err as { response?: { data?: { ocs?: { data?: { error?: string } } } } }
	return e?.response?.data?.ocs?.data?.error ?? fallback
}
