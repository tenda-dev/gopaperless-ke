/*
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Locks the behaviour of the serialization logic extracted from
 * useVisibleElementsNext (shared by the authenticated and public editors).
 * The coordinate math, page indexing, flooring, signer association and payload
 * shape must stay exactly as the authenticated orchestrator produced them.
 */
import { describe, expect, it } from 'vitest'
import {
	buildFilePagesMap,
	buildRestorePlan,
	buildSeedRecords,
	buildVisibleElementsPayload,
	getPageHeightForFile,
	globalPageForFileLocal,
	toGlobalPage,
} from '../../../../components/Request/VisibleElementsNext/shared/visibleElementsSerialization'

const twoFiles = [
	{ id: 10, name: 'a', metadata: { p: 2, d: [{ h: 800 }, { h: 800 }] }, signers: [{ signRequestId: 101, identifyMethods: [{ method: 'email', value: 'a@x' }] }] },
	{ id: 20, name: 'b', metadata: { p: 1, d: [{ h: 600 }] }, signers: [{ signRequestId: 202, identifyMethods: [{ method: 'email', value: 'b@x' }] }] },
] as any

describe('buildFilePagesMap', () => {
	it('maps each global page to its owning file in document order', () => {
		expect(buildFilePagesMap(twoFiles)).toEqual({
			1: { id: 10, fileIndex: 0, startPage: 1, fileName: 'a' },
			2: { id: 10, fileIndex: 0, startPage: 1, fileName: 'a' },
			3: { id: 20, fileIndex: 1, startPage: 3, fileName: 'b' },
		})
	})
	it('skips files with a non-finite id but still advances the page counter', () => {
		const map = buildFilePagesMap([{ id: 'nope', metadata: { p: 1 } }, { id: 7, name: 'c', metadata: { p: 1 } }] as any)
		expect(map).toEqual({ 2: { id: 7, fileIndex: 1, startPage: 2, fileName: 'c' } })
	})
})

describe('toGlobalPage / globalPageForFileLocal', () => {
	const map = buildFilePagesMap(twoFiles)
	it('maps document-local pages to global pages', () => {
		expect(toGlobalPage(map, 0, 2)).toBe(2)
		expect(toGlobalPage(map, 1, 1)).toBe(3)
	})
	it('maps (fileId, local page) to global pages', () => {
		expect(globalPageForFileLocal(map, 20, 1)).toBe(3)
	})
	it('falls back to the local page when unknown', () => {
		expect(toGlobalPage(map, 9, 4)).toBe(4)
		expect(globalPageForFileLocal(map, 999, 5)).toBe(5)
	})
})

describe('getPageHeightForFile', () => {
	it('returns the stored page height', () => {
		expect(getPageHeightForFile(twoFiles, 10, 2)).toBe(800)
		expect(getPageHeightForFile(twoFiles, 20, 1)).toBe(600)
	})
	it('returns undefined when the page or file is unknown', () => {
		expect(getPageHeightForFile(twoFiles, 10, 3)).toBeUndefined()
		expect(getPageHeightForFile(twoFiles, 55, 1)).toBeUndefined()
	})
})

describe('buildVisibleElementsPayload', () => {
	const map = buildFilePagesMap(twoFiles)

	it('serialises a placed object with floored coords, local page, matched signer and elementId', () => {
		const objects: Record<number, any[]> = {
			0: [{ id: 'o1', pageNumber: 2, x: 10.6, y: 20.9, width: 100.2, height: 30.8, signer: { identifyMethods: [{ method: 'email', value: 'a@x' }] }, visibleElement: { elementId: 5 } }],
			1: [{ id: 'o2', pageNumber: 1, x: 5.9, y: 6.9, width: 40.9, height: 12.9, signer: { identifyMethods: [{ method: 'email', value: 'b@x' }] } }],
		}
		const payload = buildVisibleElementsPayload({ files: twoFiles, filePagesMap: map, getObjects: (d) => objects[d] || [] })
		expect(payload).toEqual([
			{ type: 'signature', fileId: 10, signRequestId: 101, elementId: 5, coordinates: { page: 2, width: 100, height: 30, left: 10, top: 20 } },
			{ type: 'signature', fileId: 20, signRequestId: 202, coordinates: { page: 1, width: 40, height: 12, left: 5, top: 6 } },
		])
	})

	it('skips objects with no signer', () => {
		const payload = buildVisibleElementsPayload({ files: twoFiles, filePagesMap: map, getObjects: (d) => (d === 0 ? [{ id: 'x', pageNumber: 1, x: 1, y: 1, width: 1, height: 1 }] : []) as any })
		expect(payload).toEqual([])
	})

	it('skips objects whose page has no known height', () => {
		const files = [{ id: 10, name: 'a', metadata: { p: 2, d: [{ h: 800 }] }, signers: twoFiles[0].signers }] as any
		const m = buildFilePagesMap(files)
		const payload = buildVisibleElementsPayload({ files, filePagesMap: m, getObjects: (d) => (d === 0 ? [{ id: 'x', pageNumber: 2, x: 1, y: 1, width: 1, height: 1, signer: { identifyMethods: [{ method: 'email', value: 'a@x' }] } }] : []) as any })
		expect(payload).toEqual([])
	})

	it('skips objects whose signer matches no file signer', () => {
		const payload = buildVisibleElementsPayload({ files: twoFiles, filePagesMap: map, getObjects: (d) => (d === 0 ? [{ id: 'x', pageNumber: 1, x: 1, y: 1, width: 1, height: 1, signer: { identifyMethods: [{ method: 'email', value: 'stranger@x' }] } }] : []) as any })
		expect(payload).toEqual([])
	})
})

describe('buildSeedRecords', () => {
	it('builds placement records from the document visible elements', () => {
		const map = buildFilePagesMap(twoFiles)
		const document = {
			visibleElements: [
				{ type: 'signature', fileId: 20, signRequestId: 202, elementId: 9, coordinates: { page: 1, left: 12, top: 34, width: 50, height: 20 } },
			],
		} as any
		expect(buildSeedRecords(document, map)).toEqual([
			{ id: 'seed-9', signRequestId: 202, globalPage: 3, x: 12, y: 34, width: 50, height: 20 },
		])
	})
	it('returns an empty list when there are no elements', () => {
		expect(buildSeedRecords({ visibleElements: [] } as any, buildFilePagesMap(twoFiles))).toEqual([])
	})
})

describe('buildRestorePlan', () => {
	it('groups (element, signer) pairs by document index', () => {
		const files = [
			{ id: 10, name: 'a', metadata: { p: 1 }, signers: [{ signRequestId: 101, displayName: 'Asha', identifyMethods: [{ method: 'email', value: 'a@x' }] }] },
		] as any
		const document = {
			visibleElements: [
				{ type: 'signature', fileId: 10, signRequestId: 101, elementId: 1, coordinates: { page: 1, left: 1, top: 1, width: 1, height: 1 } },
			],
		} as any
		const plan = buildRestorePlan(document, files, files)
		expect(plan.size).toBe(1)
		const items = plan.get(0)
		expect(items).toHaveLength(1)
		expect(items![0].element.elementId).toBe(1)
		expect(items![0].signer.signRequestId).toBe(101)
	})
})
