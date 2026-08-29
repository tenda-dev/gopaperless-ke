/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Reactive, self-owned view of the signature boxes on the document.
 *
 * The presentation layer (completeness counts, per-page dots, isolation, overlap
 * banner) is driven by THIS model rather than by scraping the PDF library's
 * internal `getAllObjects` on a fragile timer. Two sources feed it:
 *
 *  1. `seed(records)` — parsed straight from the `visibleElements` array the
 *     server returns. Gives instant, correct counts the moment the modal opens,
 *     before the PDF editor has even finished restoring boxes.
 *  2. `syncFromEditor()` — reads the live editor objects. Once the editor is
 *     ready (`markEditorReady()`), it becomes the source of truth so user edits
 *     (place / move / delete) are reflected, including going back down to zero.
 *
 * This removes the "0 of N placed" bug, which was caused by reading the editor
 * before its asynchronous restore had populated any objects.
 */

import { computed, ref } from 'vue'
import type { PdfObject } from '../shared/visibleElementsShared'

export type PlacementRecord = {
	id: string
	signRequestId: number
	/** Global page number across all documents in the envelope. */
	globalPage: number
	x: number
	y: number
	width: number
	height: number
}

export type OverlapPair = {
	globalPage: number
	a: PlacementRecord
	b: PlacementRecord
}

export type UsePlacementsOptions = {
	getDocCount: () => number
	getObjects: (docIndex: number) => PdfObject[] | undefined
	/** Map a document-local page number to the global page number. */
	toGlobalPage: (docIndex: number, localPageNumber: number) => number
	getAllSignerIds: () => number[]
}

function rectsOverlap(a: PlacementRecord, b: PlacementRecord): boolean {
	return !(
		a.x + a.width <= b.x
		|| b.x + b.width <= a.x
		|| a.y + a.height <= b.y
		|| b.y + b.height <= a.y
	)
}

export function usePlacements(options: UsePlacementsOptions) {
	const seededPlacements = ref<PlacementRecord[]>([])
	const editorPlacements = ref<PlacementRecord[]>([])
	const editorReady = ref(false)

	/** Seed from the server `visibleElements` (already mapped to records). */
	function seed(records: PlacementRecord[]): void {
		seededPlacements.value = records
	}

	/** Mark the editor as the authoritative source (call after restore). */
	function markEditorReady(): void {
		editorReady.value = true
		syncFromEditor()
	}

	/** Re-read live editor objects. No-op safe before the editor exists. */
	function syncFromEditor(): void {
		const next: PlacementRecord[] = []
		const docCount = options.getDocCount()
		for (let docIndex = 0; docIndex < docCount; docIndex++) {
			const objects = options.getObjects(docIndex) || []
			objects.forEach((object) => {
				const signRequestId = Number(object.signer?.signRequestId)
				if (!object.signer || !Number.isFinite(signRequestId)) {
					return
				}
				next.push({
					id: String(object.id),
					signRequestId,
					globalPage: options.toGlobalPage(docIndex, Number(object.pageNumber)),
					x: Number(object.x),
					y: Number(object.y),
					width: Number(object.width),
					height: Number(object.height),
				})
			})
		}
		editorPlacements.value = next
	}

	function reset(): void {
		seededPlacements.value = []
		editorPlacements.value = []
		editorReady.value = false
	}

	// Once the editor is ready it wins (it reflects live edits, incl. deletions
	// down to zero); before that we trust the server response so counts are never stale.
	const placements = computed<PlacementRecord[]>(() =>
		(editorReady.value ? editorPlacements.value : seededPlacements.value))

	const countBySigner = computed<Record<number, number>>(() => {
		const counts: Record<number, number> = {}
		placements.value.forEach((placement) => {
			counts[placement.signRequestId] = (counts[placement.signRequestId] || 0) + 1
		})
		return counts
	})

	const signersByPage = computed<Record<number, number[]>>(() => {
		const byPage: Record<number, Set<number>> = {}
		placements.value.forEach((placement) => {
			const set = byPage[placement.globalPage] || (byPage[placement.globalPage] = new Set<number>())
			set.add(placement.signRequestId)
		})
		const result: Record<number, number[]> = {}
		Object.keys(byPage).forEach((page) => {
			result[Number(page)] = Array.from(byPage[Number(page)])
		})
		return result
	})

	const overlaps = computed<OverlapPair[]>(() => {
		const pairs: OverlapPair[] = []
		const byPage = new Map<number, PlacementRecord[]>()
		placements.value.forEach((placement) => {
			const list = byPage.get(placement.globalPage) || []
			list.push(placement)
			byPage.set(placement.globalPage, list)
		})
		byPage.forEach((list, globalPage) => {
			for (let i = 0; i < list.length; i++) {
				for (let j = i + 1; j < list.length; j++) {
					if (rectsOverlap(list[i], list[j])) {
						pairs.push({ globalPage, a: list[i], b: list[j] })
					}
				}
			}
		})
		return pairs
	})

	const overlappingIds = computed<Set<string>>(() => {
		const ids = new Set<string>()
		overlaps.value.forEach((pair) => {
			ids.add(pair.a.id)
			ids.add(pair.b.id)
		})
		return ids
	})

	const placedSignerIds = computed<Set<number>>(() =>
		new Set(placements.value.map((placement) => placement.signRequestId)))

	const emptySigners = computed<number[]>(() => {
		const placed = placedSignerIds.value
		return options.getAllSignerIds().filter((id) => !placed.has(id))
	})

	const placedCount = computed<number>(() => placedSignerIds.value.size)

	return {
		placements,
		seed,
		markEditorReady,
		syncFromEditor,
		reset,
		countBySigner,
		signersByPage,
		overlaps,
		overlappingIds,
		emptySigners,
		placedSignerIds,
		placedCount,
	}
}
