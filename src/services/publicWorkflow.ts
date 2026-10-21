/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * API client for the anonymous public-workflow endpoints.
 *
 * Uses @nextcloud/axios so the guest request-token (CSRF) and OCS headers are
 * attached automatically, and the HttpOnly capability cookie is sent by the
 * browser on same-origin requests. The raw capability is NEVER read here — it
 * lives only in the HttpOnly cookie.
 */
import axios from '@nextcloud/axios'
import type { AxiosProgressEvent } from 'axios'
import { generateOcsUrl } from '@nextcloud/router'

const BASE = '/apps/libresign/api/v1/public-workflow'

export interface WorkflowSigner {
	signRequestId?: number
	displayName?: string
	email?: string
	identifyMethods?: Array<{ method: string, value: string }>
	visibleElements?: unknown[]
	/** Signer custom message; maps to the sign-request `description` field. */
	description?: string
	/** DRAFT sponsorship selection: 'requester' = requester pays, 'self' = signer pays. */
	sponsorship?: { type: 'self' | 'requester' }
}

/**
 * The DRAFT document as returned by the file-show formatter (same shape the
 * save endpoints return): a single file object carrying its signers and the
 * aggregated visible elements. Only the fields the public workflow restores
 * are typed here; the server object has more.
 */
export interface WorkflowDocument {
	uuid?: string
	name?: string
	status?: number
	signers?: WorkflowSigner[]
	visibleElements?: unknown[]
}

export interface WorkflowState {
	status: string
	expiresAt: string
	hasDocument: boolean
	fileUuid?: string | null
	/** DRAFT-only requester metadata, rehydrated on resume (never auth factors). */
	requesterEmail?: string | null
	requesterIsSigner?: boolean
	/** Present on resume once a document exists; null otherwise. */
	document?: WorkflowDocument | null
}

export interface UploadResult {
	fileUuid: string
	nodeId: number
	status: number
	isDraft: boolean
	ownerMatches: boolean
}

export async function createWorkflow(): Promise<WorkflowState> {
	const { data } = await axios.post(generateOcsUrl(BASE))
	return data.ocs.data
}

export async function getWorkflow(): Promise<WorkflowState> {
	const { data } = await axios.get(generateOcsUrl(BASE))
	return data.ocs.data
}

export async function uploadDocument(
	file: File,
	onUploadProgress?: (e: AxiosProgressEvent) => void,
): Promise<UploadResult> {
	const form = new FormData()
	form.append('file', file)
	const { data } = await axios.post(generateOcsUrl(`${BASE}/document`), form, {
		headers: { 'Content-Type': 'multipart/form-data' },
		onUploadProgress,
	})
	return data.ocs.data
}

export async function saveSigners(signers: unknown[]): Promise<WorkflowDocument> {
	const { data } = await axios.post(generateOcsUrl(`${BASE}/signers`), { signers })
	return data.ocs.data
}

export async function saveVisibleElements(visibleElements: unknown[]): Promise<WorkflowDocument> {
	const { data } = await axios.post(generateOcsUrl(`${BASE}/visible-elements`), { visibleElements })
	return data.ocs.data
}

/**
 * Delete one visible element from the workflow's DRAFT document. Returns the
 * refreshed authoritative document (or null if the server could not format it).
 */
export async function deleteVisibleElement(elementId: number): Promise<WorkflowDocument | null> {
	const { data } = await axios.delete(generateOcsUrl(`${BASE}/visible-elements/{elementId}`, { elementId }))
	return data.ocs.data.document ?? null
}

export interface ClaimStatus {
	claimable: boolean
	status?: string
	requesterEmail?: string | null
	accountEmail?: string | null
	emailMatches?: boolean
	alreadyClaimedByMe?: boolean
}

export interface ClaimResult {
	uuid: string
	redirectUrl: string
}

/** Persist the requester's email + self-signer intent (DRAFT-only capture). */
export async function saveRequester(email: string | null, isSigner: boolean): Promise<void> {
	await axios.post(generateOcsUrl(`${BASE}/requester`), { email, isSigner })
}

/**
 * Authenticated claim-eligibility probe for the current browser's capability
 * cookie. Returns { claimable: false } (not an error) when there is nothing to
 * claim, and throws only on a transport/other failure.
 */
export async function getClaimStatus(): Promise<ClaimStatus> {
	const { data } = await axios.get(generateOcsUrl(`${BASE}/claim`))
	return data.ocs.data
}

/** Perform the claim after explicit confirmation; returns the signing redirect. */
export async function claimWorkflow(): Promise<ClaimResult> {
	const { data } = await axios.post(generateOcsUrl(`${BASE}/claim`))
	return data.ocs.data
}

export async function revokeWorkflow(): Promise<void> {
	await axios.delete(generateOcsUrl(BASE))
}
