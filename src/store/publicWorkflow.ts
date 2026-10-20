/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lightweight ref-based store for the public workflow page (mirrors the
 * composable style of store/upload.ts). Holds only non-secret workflow state;
 * the capability lives exclusively in the HttpOnly cookie, never here.
 */
import type { AxiosProgressEvent } from 'axios'
import { ref } from 'vue'

import type { WorkflowDocument, WorkflowSigner } from '@/services/publicWorkflow'
import * as api from '@/services/publicWorkflow'

export type Phase = 'idle' | 'loading' | 'ready' | 'expired' | 'error'

const phase = ref<Phase>('idle')
const status = ref<string>('')
const expiresAt = ref<string>('')
const hasDocument = ref<boolean>(false)
const fileUuid = ref<string | null>(null)
const errorMessage = ref<string>('')

/** An editable signer row in the public workflow (name + email only). */
export interface SignerDraft {
	displayName: string
	email: string
	/** Server sign-request id once persisted; absent for a freshly added row. */
	signRequestId?: number
	/** Draft preference: requester pays for this signer. Converted to sponsorship.type at the API boundary. */
	requesterSponsored?: boolean
	/** Optional custom message; maps to the signer `description` field. */
	description?: string
}

// Signers and visible elements are hydrated from the server's authoritative
// DRAFT document (resume payload, or a save response) — never a second source
// of truth. The capability token is NEVER mirrored here; it stays in the
// HttpOnly cookie only.
const signers = ref<SignerDraft[]>([])
// DRAFT-only requester self-signing intent. First-class store state so the
// Signers stage can show the requester chip and fold them into saveSigners as a
// genuine email signer; rehydrated from the resume payload. Never an auth factor.
const requesterEmail = ref<string | null>(null)
const requesterIsSigner = ref<boolean>(false)
const visibleElements = ref<unknown[]>([])
// The full authoritative DRAFT document (file + metadata + signers + visible
// elements) as the server returned it — the public editor's data source.
const publicDocument = ref<WorkflowDocument | null>(null)

function emailOfSigner(signer: WorkflowSigner): string {
	if (signer.email) {
		return signer.email
	}
	return signer.identifyMethods?.find(m => m.method === 'email')?.value ?? ''
}

/** Map the server document's signers to editable name/email rows. */
function mapSigners(document?: WorkflowDocument | null): SignerDraft[] {
	return (document?.signers ?? []).map(signer => ({
		displayName: signer.displayName ?? '',
		email: emailOfSigner(signer),
		signRequestId: signer.signRequestId,
		// Persisted DRAFT intent from the server is the source of truth for the
		// sponsorship indicator — never a transient dialog toggle.
		requesterSponsored: signer.sponsorship?.type === 'requester',
		description: signer.description ?? '',
	}))
}

/**
 * Replace local signer/element state with the server's authoritative document.
 * When a document exists but has no signers yet, seed a single empty row so the
 * editor always has something to fill in.
 */
function ensureEditableRow(): void {
	if (hasDocument.value && signers.value.length === 0) {
		signers.value = [{ displayName: '', email: '' }]
	}
}

function hydrateFromDocument(document?: WorkflowDocument | null): void {
	// A null/absent document means this response is not authoritative (e.g. a
	// formatting hiccup, or a step with no document yet) — preserve the current
	// client state rather than wiping unsaved edits.
	if (!document) {
		ensureEditableRow()
		return
	}
	signers.value = mapSigners(document)
	visibleElements.value = (document.visibleElements as unknown[]) ?? []
	publicDocument.value = document
	ensureEditableRow()
}

function apply(state: api.WorkflowState): void {
	status.value = state.status
	expiresAt.value = state.expiresAt
	hasDocument.value = state.hasDocument
	fileUuid.value = state.fileUuid ?? null
	requesterEmail.value = state.requesterEmail ?? null
	requesterIsSigner.value = state.requesterIsSigner ?? false
	if (!state.document) {
		publicDocument.value = null
	}
	hydrateFromDocument(state.document)
	phase.value = 'ready'
}

function toExpired(): void {
	phase.value = 'expired'
}

/** Try to resume an existing workflow from the cookie; falls back to idle. */
async function resume(): Promise<void> {
	phase.value = 'loading'
	try {
		apply(await api.getWorkflow())
	} catch (e: any) {
		if (e?.response?.status === 401) {
			phase.value = 'idle'
		} else {
			phase.value = 'error'
			errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Could not load the workflow'
		}
	}
}

async function start(): Promise<void> {
	phase.value = 'loading'
	try {
		apply(await api.createWorkflow())
	} catch (e: any) {
		phase.value = 'error'
		errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Could not start the workflow'
	}
}

async function upload(file: File, onUploadProgress?: (e: AxiosProgressEvent) => void): Promise<api.UploadResult | null> {
	try {
		const result = await api.uploadDocument(file, onUploadProgress)
		hasDocument.value = true
		fileUuid.value = result.fileUuid
		hydrateFromDocument(null)
		return result
	} catch (e: any) {
		if (e?.response?.status === 401) {
			toExpired()
			return null
		}
		errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Upload failed'
		throw e
	}
}

async function saveSigners(payload: unknown[]): Promise<void> {
	try {
		// The save pipeline reconciles signers (create/update/remove) and returns
		// the authoritative document; hydrate from it so the UI reflects the
		// server state (no duplicates, canonical order) instead of local guesses.
		hydrateFromDocument(await api.saveSigners(payload))
	} catch (e: any) {
		if (e?.response?.status === 401) {
			toExpired()
			return
		}
		errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Could not save signers'
		throw e
	}
}

async function saveRequester(email: string | null, isSigner: boolean): Promise<void> {
	// DRAFT-only capture of the requester's email + self-signer intent; records
	// intent and sends/charges nothing. Errors propagate so the caller can react.
	await api.saveRequester(email, isSigner)
	requesterEmail.value = email
	requesterIsSigner.value = isSigner
}

async function saveVisibleElements(elements: unknown[]): Promise<void> {
	try {
		hydrateFromDocument(await api.saveVisibleElements(elements))
	} catch (e: any) {
		if (e?.response?.status === 401) {
			toExpired()
			return
		}
		errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Could not save signature positions'
		throw e
	}
}

async function deleteVisibleElement(elementId: number): Promise<void> {
	try {
		// On success the server returns the refreshed authoritative document; on a
		// failed request we rethrow WITHOUT mutating state, preserving the edit.
		hydrateFromDocument(await api.deleteVisibleElement(elementId))
	} catch (e: any) {
		if (e?.response?.status === 401) {
			toExpired()
			return
		}
		errorMessage.value = e?.response?.data?.ocs?.data?.message ?? 'Could not remove the signature position'
		throw e
	}
}

export function usePublicWorkflow() {
	return {
		phase,
		status,
		expiresAt,
		hasDocument,
		fileUuid,
		errorMessage,
		resume,
		start,
		upload,
		saveSigners,
		saveRequester,
		saveVisibleElements,
		deleteVisibleElement,
		signers,
		visibleElements,
		requesterEmail,
		requesterIsSigner,
		document: publicDocument,
	}
}
