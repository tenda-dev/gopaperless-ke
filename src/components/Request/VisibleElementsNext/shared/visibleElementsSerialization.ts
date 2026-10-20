/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure, store-agnostic serialization + page-geometry + restore-mapping for the
 * signature-positions editor. Extracted VERBATIM from useVisibleElementsNext so
 * the authenticated orchestrator and the public (anonymous) workflow share ONE
 * source of truth for the coordinate math, page indexing and signer
 * association. Nothing here imports Vue, Pinia, a store, an API client or a
 * workflow service — all inputs are passed in explicitly.
 *
 * Do NOT alter the coordinate calculations, rounding, page indexing, signer
 * association, element types, field names, defaults, filtering or ordering:
 * this is a behaviour-preserving move, not a rewrite.
 */
import {
	findFileById,
	getFileSigners,
	getVisibleElementsFromDocument,
	idsMatch,
	normaliseVisibleElement,
	toSignerSummaryRecord,
	toVisibleElementsFile,
	type EditableRequestChildFile,
	type EditableRequestSigner,
	type EditableVisibleElementPayload,
	type PdfObject,
} from './visibleElementsShared'
import type { DocumentLike, FileLike } from '../../../../services/visibleElementsService'
import type { PlacementRecord } from '../composables/usePlacements'
import type { IdentifyMethodRecord, SignerSummaryRecord, VisibleElementRecord } from '../../../../types/index'

export type FilePageInfo = {
	id: number
	fileIndex: number
	startPage: number
	fileName: string
}

/**
 * Map every global page number to its owning file. Global page numbering runs
 * in document order across all files in the envelope (1-based).
 */
export function buildFilePagesMap(files: EditableRequestChildFile[]): Record<number, FilePageInfo> {
	const filePagesMap: Record<number, FilePageInfo> = {}
	let currentPage = 1
	files.forEach((file, index) => {
		const pageCount = file.metadata?.p || 0
		const fileId = typeof file.id === 'number' ? file.id : Number(file.id)
		if (!Number.isFinite(fileId)) {
			currentPage += pageCount
			return
		}
		for (let pageIndex = 0; pageIndex < pageCount; pageIndex++) {
			filePagesMap[currentPage + pageIndex] = {
				id: fileId,
				fileIndex: index,
				startPage: currentPage,
				fileName: file.name ?? '',
			}
		}
		currentPage += pageCount
	})
	return filePagesMap
}

/** Map a document-local page number to the global page number. */
export function toGlobalPage(filePagesMap: Record<number, FilePageInfo>, docIndex: number, localPageNumber: number): number {
	for (const key of Object.keys(filePagesMap)) {
		const info = filePagesMap[Number(key)]
		if (info.fileIndex === docIndex) {
			return info.startPage + localPageNumber - 1
		}
	}
	return localPageNumber
}

/** Map a (fileId, document-local page) to the global page number. */
export function globalPageForFileLocal(filePagesMap: Record<number, FilePageInfo>, fileId: number, localPage: number): number {
	for (const key of Object.keys(filePagesMap)) {
		const info = filePagesMap[Number(key)]
		if (info.id === fileId) {
			return info.startPage + localPage - 1
		}
	}
	return localPage
}

/** Height (PDF points) of a document-local page of a given file, if known. */
export function getPageHeightForFile(files: EditableRequestChildFile[], fileId: number, page: number): number | undefined {
	const fileInfo = files.find(file => file.id === fileId)
	return fileInfo?.metadata?.d?.[page - 1]?.h
}

/**
 * Seed records parsed straight from the server `visibleElements`, so placement
 * counts are correct the instant the editor opens, before boxes are restored.
 */
export function buildSeedRecords(
	visibleElementsDocument: DocumentLike,
	filePagesMap: Record<number, FilePageInfo>,
): PlacementRecord[] {
	const elements = getVisibleElementsFromDocument(visibleElementsDocument)
	const records: PlacementRecord[] = []
	elements.forEach((element) => {
		const normalised = normaliseVisibleElement(element)
		if (!normalised) {
			return
		}
		records.push({
			id: `seed-${normalised.elementId}`,
			signRequestId: normalised.signRequestId,
			globalPage: globalPageForFileLocal(filePagesMap, normalised.fileId, Number(normalised.coordinates.page)),
			x: Number(normalised.coordinates.left),
			y: Number(normalised.coordinates.top),
			width: Number(normalised.coordinates.width ?? 0),
			height: Number(normalised.coordinates.height ?? 0),
		})
	})
	return records
}

/**
 * Build the per-document restore plan: for each document index, the list of
 * (element, signer) pairs to hand to the editor's `addSigner`. Pure; the caller
 * performs the async editor calls. Mirrors the mapping in updateSigners().
 */
export function buildRestorePlan(
	visibleElementsDocument: DocumentLike,
	visibleElementsFiles: FileLike[],
	files: EditableRequestChildFile[],
): Map<number, Array<{ element: VisibleElementRecord, signer: SignerSummaryRecord }>> {
	const fileIndexById = new Map<string, number>(files.map((file, index) => [String(file.id), index]))
	const elements = getVisibleElementsFromDocument(visibleElementsDocument)
	const elementsByDoc = new Map<number, Array<{ element: VisibleElementRecord, signer: SignerSummaryRecord }>>()

	elements.forEach((element) => {
		const normalisedElement = normaliseVisibleElement(element)
		if (!normalisedElement) {
			return
		}
		const fileInfo = findFileById(visibleElementsFiles, normalisedElement.fileId)
		if (!fileInfo) {
			return
		}
		const docIndex = fileIndexById.get(String(normalisedElement.fileId))
		if (docIndex === undefined) {
			return
		}
		const signer = getFileSigners(fileInfo).find((item) => idsMatch(item.signRequestId, normalisedElement.signRequestId))
		const signerRecord = toSignerSummaryRecord(signer)
		if (!signerRecord) {
			return
		}
		const items = elementsByDoc.get(docIndex) || []
		items.push({ element: normalisedElement, signer: signerRecord })
		elementsByDoc.set(docIndex, items)
	})

	return elementsByDoc
}

/**
 * Serialize the editor's placed objects back into the save payload. Ported
 * verbatim from VisibleElements.vue via useVisibleElementsNext — do not alter
 * the coordinate math or the payload shape.
 *
 * @param getObjects returns the editor's objects for a document index
 *                   (e.g. pdfElements.getAllObjects(docIndex)).
 */
export function buildVisibleElementsPayload(params: {
	files: EditableRequestChildFile[]
	filePagesMap: Record<number, FilePageInfo>
	getObjects: (docIndex: number) => PdfObject[]
}): EditableVisibleElementPayload[] {
	const { files: currentFiles, filePagesMap, getObjects } = params
	const visibleElements: EditableVisibleElementPayload[] = []
	const numDocuments = currentFiles.length

	for (let docIndex = 0; docIndex < numDocuments; docIndex++) {
		const objects = getObjects(docIndex) || []
		objects.forEach((object: PdfObject) => {
			if (!object.signer) return

			let globalPageNumber = object.pageNumber
			const pageInfos = Object.keys(filePagesMap).map((page) => filePagesMap[Number(page)])
			for (const info of pageInfos) {
				if (info.fileIndex === docIndex) {
					globalPageNumber = info.startPage + object.pageNumber - 1
					break
				}
			}

			const pageInfo = filePagesMap[globalPageNumber]
			if (!pageInfo) {
				return
			}
			const pageHeight = getPageHeightForFile(currentFiles, pageInfo.id, object.pageNumber)
			if (!pageHeight) {
				return
			}

			const coordinates = {
				page: globalPageNumber,
				width: Math.floor(object.width),
				height: Math.floor(object.height),
				left: Math.floor(object.x),
				top: Math.floor(object.y),
			}

			const targetFileId = pageInfo.id
			const pageNumber = globalPageNumber - pageInfo.startPage + 1

			const fileInfo = currentFiles.find((file) => file.id === targetFileId)
			if (!fileInfo || !Array.isArray(fileInfo.signers)) {
				return
			}
			const envIdentifyMethods = Array.isArray(object.signer.identifyMethods) ? object.signer.identifyMethods : []
			const envIdMethods = envIdentifyMethods.map((method: IdentifyMethodRecord) => `${method.method}:${method.value}`).sort().join('|')
			const candidate = fileInfo.signers.find((signer: EditableRequestSigner) => {
				const childIdentifyMethods = Array.isArray(signer.identifyMethods) ? signer.identifyMethods : []
				const childIdMethods = childIdentifyMethods.map((method: IdentifyMethodRecord) => `${method.method}:${method.value}`).sort().join('|')
				return childIdMethods === envIdMethods
			})
			const signRequestId = Number(candidate?.signRequestId)
			if (!Number.isFinite(signRequestId)) {
				return
			}

			visibleElements.push({
				type: 'signature',
				fileId: targetFileId,
				signRequestId,
				...(object.visibleElement?.elementId !== undefined ? { elementId: object.visibleElement.elementId } : {}),
				coordinates: {
					...coordinates,
					page: pageNumber,
				},
			})
		})
	}

	return visibleElements
}

// `toVisibleElementsFile` is re-exported so callers can build the FileLike[]
// inputs for buildRestorePlan without reaching back into the shared module.
export { toVisibleElementsFile }
