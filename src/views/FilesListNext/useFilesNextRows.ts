/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * READ-ONLY adapter for the experimental `/f/filelist/next` proposal.
 *
 * This wrapper maps the EXISTING LibreSign `files` Pinia store into a flat,
 * presentation-friendly row model. It is intentionally read-only:
 *   - it only reads `filesStore.filesSorted()` and derives fields,
 *   - it NEVER mutates the store, its state, getters or actions,
 *   - every action/mutation stays in the original store and is dispatched
 *     from the component by calling the store's own methods with `row.raw`.
 *
 * If the store's per-document shape ever changes, this is the single place
 * that needs updating — the presentation component stays agnostic.
 */
import { computed } from 'vue'

import { useFilesStore } from '../../store/files.js'

/** A store file/document record — loosely typed on purpose (the store is JS). */
export type StoreFile = Record<string, any>

export type FilesNextRow = {
	/** stable list key — the uuid, not the numeric id */
	key: string
	id: number | string
	nodeId: number | null
	uuid: string | null
	name: string
	/** drives the thumbnail badge, e.g. `pdf` */
	ext: string
	/** rendered on the sub-line beneath the name; may be an email */
	requester: string
	/** 0 Draft · 1 Ready · 2 Partial · 3 Signed (also -1/4/5 exist) */
	statusCode: number
	/** API text — used verbatim, never re-invented */
	statusLabel: string
	/** denominator of X / Y */
	signersTotal: number
	/** numerator — see caveat: 0 until per-file detail is loaded */
	signersSigned: number
	/** relative-time source */
	updated: string | number | null
	created: string | number | null
	canSign: boolean
	/** null for drafts */
	signUuid: string | null
	nodeType: string | undefined
	/** untouched store object — passed straight to the store's own dispatchers */
	raw: StoreFile
}

/**
 * A signer is "signed" when its `signed` field is truthy. The store treats it
 * as either a timestamp string or an array (see isPartialSigned/isFullSigned).
 */
function isSignerSigned(signer: any): boolean {
	const signed = signer?.signed
	if (Array.isArray(signed)) {
		return signed.length > 0
	}
	return Boolean(signed)
}

/** Pure store-record -> row-model mapping. Exported for unit testing. */
export function mapFileToRow(file: StoreFile): FilesNextRow {
	const signers: any[] = Array.isArray(file?.signers) ? file.signers : []
	const requestedBy = (file?.requested_by ?? {}) as Record<string, any>

	return {
		// Use `||` (not `??`): LibreSign returns uuid: "" for files without a
		// signature request. `??` would keep the empty string, giving several
		// rows an identical key and breaking Vue's row reuse (selection lands on
		// the wrong row). Fall back to the unique numeric id instead.
		key: String(file?.uuid || file?.id || ''),
		id: file?.id,
		nodeId: typeof file?.nodeId === 'number' ? file.nodeId : (file?.nodeId ?? null),
		uuid: file?.uuid ?? null,
		name: file?.name ?? '',
		ext: file?.metadata?.extension ?? '',
		// The list endpoint exposes displayName; older UI used userId. Fall back gracefully.
		requester: requestedBy.displayName || requestedBy.userId || requestedBy.email || '',
		statusCode: Number(file?.status ?? 0),
		statusLabel: file?.statusText ?? '',
		signersTotal: Number(file?.signersCount ?? signers.length ?? 0),
		signersSigned: signers.filter(isSignerSigned).length,
		// status_changed_at is optional and absent in the list payload -> created_at fallback.
		updated: file?.metadata?.status_changed_at ?? file?.created_at ?? null,
		created: file?.created_at ?? null,
		canSign: Boolean(file?.canSign),
		signUuid: file?.signUuid ?? null,
		nodeType: file?.nodeType,
		raw: file,
	}
}

/* ------------------------------------------------------------------ *
 * TEMPORARY TEST OVERRIDE — TODO:- remove before shipping.
 * Forces long names onto the first three rows to stress-test truncation:
 *   - two ~100-char multi-word (lorem) titles → wrapping + ellipsis
 *   - one long unbroken underscore token → PDF-style filename word-break
 * Presentation-only; never written back to the store.
 * ------------------------------------------------------------------ */
const TEST_LONG_NAMES = false
const TEST_NAMES: string[] = [
	'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et',
	'Ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo cons',
	'Supercalifragilisticexpialidocious_Employment_Contract_Final_Version_2026_Signed_Copy_Really_Long_v3',
]

/**
 * Reactive read-only view of the files store as a row list.
 * Returns the store handle too, so the component can call the store's own
 * load/pagination/action methods directly (read-only never means read-nothing).
 */
export function useFilesNextRows() {
	const filesStore = useFilesStore()

	const rows = computed<FilesNextRow[]>(() => {
		const list = filesStore.filesSorted().filter(Boolean).map(mapFileToRow)
		if (TEST_LONG_NAMES) {
			TEST_NAMES.forEach((name, i) => {
				if (list[i]) {
					list[i] = { ...list[i], name }
				}
			})
		}
		return list
	})

	return { filesStore, rows }
}
