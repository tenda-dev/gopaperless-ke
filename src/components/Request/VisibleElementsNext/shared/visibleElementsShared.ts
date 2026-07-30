/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared normalise / conversion helpers for the signature-position editors.
 *
 * These are lifted VERBATIM from the original `VisibleElements.vue` so that
 * `VisibleElementsNext` produces an identical save payload. It is deliberately a faithful copy so both the old
 * and new editors share one source of truth.
 */

import { generateUrl } from '@nextcloud/router'

import {
	findFileById,
	getFileSigners,
	getFileUrl,
	getVisibleElementsFromDocument,
	idsMatch,
	type DocumentLike,
	type FileLike,
	type SignerLike,
} from '../../../../services/visibleElementsService'
import type { useFilesStore } from '../../../../store/files.js'
import type {
	IdentifyMethodRecord,
	RequestSignatureVisibleElementPayload,
	SignerSummaryRecord,
	ValidationMetadataRecord,
	VisibleElementRecord,
} from '../../../../types/index'

type FilesStoreContract = ReturnType<typeof useFilesStore>
export type EditableRequestFile = ReturnType<FilesStoreContract['getEditableFile']>
export type EditableRequestChildFile = NonNullable<NonNullable<EditableRequestFile['files']>[number]>
export type EditableRequestSigner = NonNullable<NonNullable<EditableRequestFile['signers']>[number]>

export type EditableVisibleElementPayload = Omit<RequestSignatureVisibleElementPayload, 'type' | 'elementId'> & {
	type: 'signature'
	elementId?: RequestSignatureVisibleElementPayload['elementId']
}

export type PdfObject = {
	id: string
	type: string
	x: number
	y: number
	width: number
	height: number
	signer?: SignerSummaryRecord | null
	visibleElement?: VisibleElementRecord | null
	documentIndex?: number
	pageNumber: number
}

export function toRecord(value: unknown): Record<string, unknown> | null {
	return typeof value === 'object' && value !== null ? value as Record<string, unknown> : null
}

export function isIdentifyMethodRecord(value: unknown): value is IdentifyMethodRecord {
	const candidate = toRecord(value)
	return candidate !== null
		&& typeof candidate.method === 'string'
		&& typeof candidate.value === 'string'
		&& typeof candidate.mandatory === 'number'
}

export function normaliseVisibleElement(element: unknown): VisibleElementRecord | null {
	const candidate = toRecord(element)
	const coordinates = toRecord(candidate?.coordinates)
	if (!candidate || !coordinates || candidate.type !== 'signature') {
		return null
	}

	const page = Number(coordinates.page)
	const left = Number(coordinates.left)
	const top = Number(coordinates.top)
	const fileId = Number(candidate.fileId)
	const signRequestId = Number(candidate.signRequestId)
	const elementId = Number(candidate.elementId)
	const width = coordinates.width === undefined ? undefined : Number(coordinates.width)
	const height = coordinates.height === undefined ? undefined : Number(coordinates.height)

	if (![page, left, top, fileId, signRequestId, elementId].every(Number.isFinite)) {
		return null
	}

	if ((width !== undefined && !Number.isFinite(width)) || (height !== undefined && !Number.isFinite(height))) {
		return null
	}

	return {
		type: 'signature',
		elementId,
		fileId,
		signRequestId,
		coordinates: {
			page,
			left,
			top,
			...(width !== undefined ? { width } : {}),
			...(height !== undefined ? { height } : {}),
		},
	}
}

export function normaliseVisibleElementList(elements: unknown): VisibleElementRecord[] | undefined {
	if (!Array.isArray(elements)) {
		return undefined
	}

	return elements
		.map(normaliseVisibleElement)
		.filter((element): element is VisibleElementRecord => element !== null)
}

export function isPdfObject(value: unknown): value is PdfObject {
	const candidate = toRecord(value)
	return candidate !== null
		&& typeof candidate.pageNumber === 'number'
		&& typeof candidate.x === 'number'
		&& typeof candidate.y === 'number'
		&& typeof candidate.width === 'number'
		&& typeof candidate.height === 'number'
}

export function getUrlValue(value: unknown): string | undefined {
	const candidate = toRecord(value)
	return candidate && typeof candidate.url === 'string' ? candidate.url : undefined
}

export function normaliseMetadata(metadata: unknown): Partial<ValidationMetadataRecord> | undefined {
	const candidate = toRecord(metadata)
	return candidate ? candidate as Partial<ValidationMetadataRecord> : undefined
}

export function normaliseEditableRequestSigner(signer: unknown): EditableRequestSigner | null {
	const candidate = toRecord(signer)
	if (!candidate) {
		return null
	}

	return {
		signRequestId: Number.isFinite(Number(candidate.signRequestId)) ? Number(candidate.signRequestId) : 0,
		displayName: typeof candidate.displayName === 'string' ? candidate.displayName : '',
		email: typeof candidate.email === 'string' ? candidate.email : '',
		signed: null,
		status: Number.isFinite(Number(candidate.status)) ? Number(candidate.status) : 0,
		statusText: typeof candidate.statusText === 'string' ? candidate.statusText : '',
		identifyMethods: Array.isArray(candidate.identifyMethods)
			? candidate.identifyMethods.filter(isIdentifyMethodRecord)
			: undefined,
		localKey: typeof candidate.localKey === 'string' ? candidate.localKey : undefined,
		me: typeof candidate.me === 'boolean' ? candidate.me : undefined,
		visibleElements: normaliseVisibleElementList(candidate.visibleElements),
	}
}

export function toVisibleElementsSigner(signer: unknown): SignerLike | null {
	const normalisedSigner = normaliseEditableRequestSigner(signer)
	if (!normalisedSigner) {
		return null
	}

	return {
		signRequestId: normalisedSigner.signRequestId,
		displayName: normalisedSigner.displayName,
		email: normalisedSigner.email,
		identifyMethods: normalisedSigner.identifyMethods,
		signed: normalisedSigner.signed,
		status: normalisedSigner.status,
		statusText: normalisedSigner.statusText,
		me: normalisedSigner.me,
		localKey: normalisedSigner.localKey,
		visibleElements: normaliseVisibleElementList(normalisedSigner.visibleElements) ?? null,
	}
}

export function toSignerSummaryRecord(signer: SignerLike | EditableRequestSigner | ReturnType<typeof getFileSigners>[number] | null | undefined): SignerSummaryRecord | null {
	if (!signer) {
		return null
	}

	return {
		signRequestId: Number.isFinite(Number(signer.signRequestId)) ? Number(signer.signRequestId) : 0,
		displayName: signer.displayName ?? '',
		email: signer.email ?? '',
		signed: null,
		status: 0,
		statusText: '',
		...(Array.isArray(signer.identifyMethods) ? { identifyMethods: signer.identifyMethods } : {}),
	}
}

export function normaliseEditableRequestFile(file: unknown): EditableRequestChildFile | null {
	const candidate = toRecord(file)
	if (!candidate) {
		return null
	}

	const id = typeof candidate.id === 'number' ? candidate.id : Number(candidate.id)
	const name = typeof candidate.name === 'string' ? candidate.name : undefined
	const nestedFiles = Array.isArray(candidate.files)
		? candidate.files.map(normaliseEditableRequestFile).filter((row): row is EditableRequestChildFile => row !== null)
		: undefined
	const signers = Array.isArray(candidate.signers)
		? candidate.signers.map(normaliseEditableRequestSigner).filter((row): row is EditableRequestSigner => row !== null)
		: undefined
	const nestedFileReference = typeof candidate.file === 'object' && candidate.file !== null
		? normaliseEditableRequestFile(candidate.file)
		: undefined
	const fileReference = typeof candidate.file === 'string' || candidate.file === null
		? candidate.file
		: nestedFileReference && Object.keys(nestedFileReference).length > 0
			? nestedFileReference
			: undefined
	const url = getUrlValue(candidate)

	return {
		...(Number.isFinite(id) ? { id } : {}),
		...(name !== undefined ? { name } : {}),
		...(url !== undefined ? { url } : {}),
		...(fileReference !== undefined ? { file: fileReference } : {}),
		...(nestedFiles !== undefined ? { files: nestedFiles } : {}),
		...(normaliseMetadata(candidate.metadata) !== undefined ? { metadata: normaliseMetadata(candidate.metadata) } : {}),
		...(normaliseVisibleElementList(candidate.visibleElements) !== undefined ? { visibleElements: normaliseVisibleElementList(candidate.visibleElements) } : {}),
		...(signers !== undefined ? { signers } : {}),
	} as EditableRequestChildFile
}

export function toVisibleElementsFile(file: EditableRequestChildFile): FileLike {
	const nestedFiles = Array.isArray(file.files)
		? file.files.map((nestedFile) => toVisibleElementsFile(nestedFile as EditableRequestChildFile))
		: undefined
	const signers = Array.isArray(file.signers)
		? file.signers.map(toVisibleElementsSigner).filter((row): row is SignerLike => row !== null)
		: undefined
	const fileReference = typeof file.file === 'string' || file.file === null
		? file.file
		: file.file
			? toVisibleElementsFile(file.file as EditableRequestChildFile)
			: undefined
	const fileUrl = typeof (file as { url?: unknown }).url === 'string'
		? (file as { url?: string }).url
		: undefined

	return {
		id: file.id,
		name: file.name,
		...(fileUrl !== undefined ? { url: fileUrl } : {}),
		...(fileReference !== undefined ? { file: fileReference } : {}),
		...(normaliseMetadata(file.metadata) !== undefined ? { metadata: normaliseMetadata(file.metadata) } : {}),
		...(normaliseVisibleElementList(file.visibleElements) !== undefined ? { visibleElements: normaliseVisibleElementList(file.visibleElements) } : {}),
		...(signers !== undefined ? { signers } : {}),
		...(nestedFiles !== undefined ? { files: nestedFiles } : {}),
	}
}

export function toVisibleElementsDocument(document: EditableRequestFile): DocumentLike {
	return {
		id: document.id,
		uuid: document.uuid,
		name: document.name,
		status: document.status,
		statusText: document.statusText,
		metadata: normaliseMetadata(document.metadata),
		settings: document.settings,
		visibleElements: normaliseVisibleElementList(document.visibleElements) ?? null,
		signers: Array.isArray(document.signers)
			? document.signers.map(toVisibleElementsSigner).filter((row): row is SignerLike => row !== null)
			: [],
		files: Array.isArray(document.files)
			? document.files.map((file) => toVisibleElementsFile(file as EditableRequestChildFile))
			: [],
	}
}

export const normaliseVisibleElements = (elements: VisibleElementRecord[]): VisibleElementRecord[] =>
	elements.flatMap((element) => {
		if (element.type !== 'signature') {
			return []
		}

		if (!element.coordinates) {
			return []
		}

		const page = Number(element.coordinates.page)
		const left = Number(element.coordinates.left)
		const top = Number(element.coordinates.top)
		const normalisedFileId = Number(element.fileId)
		const normalisedSignRequestId = Number(element.signRequestId)
		const normalisedElementId = Number(element.elementId)
		const rawWidth = element.coordinates.width
		const rawHeight = element.coordinates.height
		const width = rawWidth === undefined ? undefined : Number(rawWidth)
		const height = rawHeight === undefined ? undefined : Number(rawHeight)

		if (![page, left, top].every(Number.isFinite)) {
			return []
		}

		if (!Number.isFinite(normalisedFileId) || !Number.isFinite(normalisedSignRequestId) || !Number.isFinite(normalisedElementId)) {
			return []
		}

		if ((width !== undefined && !Number.isFinite(width)) || (height !== undefined && !Number.isFinite(height))) {
			return []
		}

		return [{
			type: 'signature',
			elementId: normalisedElementId,
			fileId: normalisedFileId,
			signRequestId: normalisedSignRequestId,
			coordinates: {
				page,
				left,
				top,
				...(width !== undefined ? { width } : {}),
				...(height !== undefined ? { height } : {}),
			},
		} satisfies VisibleElementRecord]
	})

// Re-export the service helpers used alongside these so the orchestrator has one import site.
export {
	aggregateVisibleElementsByFiles,
	findFileById,
	getFileSigners,
	getFileUrl,
	getVisibleElementsFromDocument,
	idsMatch,
} from '../../../../services/visibleElementsService'
export type { DocumentLike, FileLike, SignerLike } from '../../../../services/visibleElementsService'
