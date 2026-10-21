/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Public (anonymous) signature-positions editor orchestration. Dedicated to the
 * public workflow and ISOLATED from the authenticated orchestrator: it imports
 * NO files store and calls NO authenticated endpoint. It reuses the shared,
 * store-agnostic engine — the serializer (visibleElementsSerialization), the
 * placement model (usePlacements), signer colours (useSignerColors), the shared
 * normalisers, and PdfEditorNext's exposed methods — and sources its document +
 * persistence from the public workflow store (hydrated document + public
 * saveVisibleElements / deleteVisibleElement). Scoped to ONE document.
 */
import { getCapabilities } from '@nextcloud/capabilities'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, nextTick, ref } from 'vue'

import { usePublicWorkflow } from '../../../store/publicWorkflow'
import { usePlacements } from '../../Request/VisibleElementsNext/composables/usePlacements'
import { useSignerColors } from '../../Request/VisibleElementsNext/composables/useSignerColors'
import {
	buildFilePagesMap as serialiseFilePagesMap,
	buildRestorePlan as serialiseRestorePlan,
	buildSeedRecords as serialiseSeedRecords,
	buildVisibleElementsPayload as serialiseVisibleElements,
	toGlobalPage as toGlobalPageIn,
} from '../../Request/VisibleElementsNext/shared/visibleElementsSerialization'
import {
	getFileUrl,
	getVisibleElementsFromDocument,
	idsMatch,
	normaliseEditableRequestFile,
	toSignerSummaryRecord,
	toVisibleElementsDocument,
	toVisibleElementsFile,
	type EditableRequestChildFile,
	type EditableRequestFile,
} from '../../Request/VisibleElementsNext/shared/visibleElementsShared'
import type { PdfObject } from '../../Request/VisibleElementsNext/shared/visibleElementsShared'
import type { RailPage } from '../../Request/VisibleElementsNext/VePageRail.vue'
import { showError, showSuccess } from '../../../services/toast.ts'
import type { LibresignCapabilities, SignerSummaryRecord } from '../../../types/index'

type PdfInput = string | Blob
type PdfEditorRef = {
	pdfElements?: {
		getAllObjects: (docIndex: number) => PdfObject[]
		selectPage?: (docIndex: number, pageIndex: number) => void
		selectedDocIndex?: number
		isAddingMode?: boolean
	} | null
	addSigner?: (signer: SignerSummaryRecord, visibleElement: unknown, options?: { documentIndex?: number }) => Promise<void> | void
	startAddingSigner?: (signer: SignerSummaryRecord, size: { width: number, height: number }) => boolean
	dropSignerAtView?: (signer: SignerSummaryRecord, size: { width: number, height: number }, bottomInset?: number) => string | false
	cancelAdding?: () => void
}

const DESKTOP_BOX_SCALE = 0.7
const MOBILE_SHEET_PEEK_INSET = 208

export function usePublicVisibleElements() {
	const store = usePublicWorkflow()

	const capabilities = (getCapabilities() as LibresignCapabilities) ?? {}
	const signElementsConfig = capabilities.libresign?.config?.['sign-elements'] ?? {
		'full-signature-width': 0,
		'full-signature-height': 0,
	}
	// Box size must match the authenticated editor exactly: take the raw
	// configured full-signature size (0 when unset → PdfEditorNext applies its own
	// default), NOT a local fallback. A local `|| 350/100` made public boxes render
	// larger than the authenticated flow when the capability was unset.
	const width = ref<number>(Number(signElementsConfig['full-signature-width']) || 0)
	const height = ref<number>(Number(signElementsConfig['full-signature-height']) || 0)

	const pdfEditor = ref<PdfEditorRef | null>(null)
	const modal = ref(false)
	const loading = ref(false)
	const pdfReady = ref(false)
	const elementsLoaded = ref(false)
	const editMode = ref(false)
	const focusedSignerId = ref<number | null>(null)
	const placing = ref(false)
	const signerSelected = ref<SignerSummaryRecord | null>(null)
	const highlightId = ref<string | null>(null)
	const pdfEditorFiles = ref<PdfInput[]>([])
	const filePagesMap = ref<ReturnType<typeof serialiseFilePagesMap>>({})
	const isMobile = ref(false)
	const currentGlobalPage = ref(1)

	function getPdfEditor(): PdfEditorRef | null {
		return pdfEditor.value
	}
	function getPdfElements() {
		return getPdfEditor()?.pdfElements ?? null
	}

	// The authoritative DRAFT document from the public store.
	const document = computed<EditableRequestFile>(() => (store.document.value ?? {}) as EditableRequestFile)

	// The PUBLIC, capability-gated PDF endpoint. It is cookie-resolved on the
	// server (no uuid in the URL, nothing browser-supplied is trusted) and serves
	// ONLY this workflow's own draft. We use it instead of the generic
	// libresign.page.getPdf (/p/pdf/{uuid}) URL that FileService::toArray() embeds
	// in the document payload: that route authorises via the logged-in user and
	// is gated by restrict_validation_document_access / make_validation_url_private,
	// so an anonymous visitor is rejected there (the editor's 403).
	const PUBLIC_PDF_URL = generateUrl('/apps/libresign/p/workflow/pdf')

	// Single-document: synthesise a file from the store document so the serializer
	// has the page geometry (metadata.d). Its URL is the public capability route.
	const fallbackFile = computed<EditableRequestChildFile | null>(() => {
		const normalised = normaliseEditableRequestFile({
			id: (document.value as { id?: unknown }).id,
			name: document.value.name,
			file: PUBLIC_PDF_URL,
			metadata: document.value.metadata,
			signers: document.value.signers,
			visibleElements: document.value.visibleElements,
		})
		if (!normalised) {
			return null
		}
		return getFileUrl(toVisibleElementsFile(normalised)) ? normalised : null
	})

	const documentFiles = computed<EditableRequestChildFile[]>(() => {
		const files = document.value.files
		if (Array.isArray(files) && files.length > 0 && files.some((file) => getFileUrl(toVisibleElementsFile(file)) !== null)) {
			return files
		}
		return fallbackFile.value ? [fallbackFile.value] : []
	})

	const visibleElementsDocument = computed(() => toVisibleElementsDocument(document.value))
	const visibleElementsFiles = computed(() => documentFiles.value.map(toVisibleElementsFile))
	// Always fetch via the capability endpoint, never the server-embedded
	// authenticated/validation URL. One document per workflow → one URL; aligned
	// 1:1 with pdfFileNames (both derived from documentFiles).
	const pdfFiles = computed<string[]>(() => documentFiles.value.map(() => PUBLIC_PDF_URL))
	const pdfFileNames = computed(() => documentFiles.value.map((file) => `${file.name}.${file.metadata?.extension || 'pdf'}`))

	const signerList = computed<SignerSummaryRecord[]>(() => (Array.isArray(document.value.signers) ? document.value.signers : [])
		.map(toSignerSummaryRecord)
		.filter((signer): signer is SignerSummaryRecord => signer !== null))
	const pdfEditorSigners = computed(() => signerList.value)
	const signerCount = computed(() => signerList.value.length)

	function toGlobalPage(docIndex: number, localPageNumber: number): number {
		return toGlobalPageIn(filePagesMap.value, docIndex, localPageNumber)
	}

	const {
		placements,
		seed,
		markEditorReady,
		syncFromEditor,
		reset: resetPlacements,
		countBySigner,
		signersByPage,
		overlaps,
		overlappingIds,
		emptySigners,
		placedCount,
	} = usePlacements({
		getDocCount: () => documentFiles.value.length,
		getObjects: (docIndex: number) => getPdfElements()?.getAllObjects(docIndex),
		toGlobalPage,
		getAllSignerIds: () => signerList.value.map((signer) => signer.signRequestId).filter((id) => Number.isFinite(id)),
	})

	const { colorMap, colorFor, registerKeys, reset: resetColors } = useSignerColors()

	function signerLabel(signer: SignerSummaryRecord | null | undefined): string {
		if (!signer) {
			return ''
		}
		return signer.displayName || signer.email || String(signer.signRequestId)
	}

	const activeSignRequestId = computed<number | null>(() => {
		if (placing.value && signerSelected.value) {
			return Number(signerSelected.value.signRequestId)
		}
		return focusedSignerId.value
	})
	const activeSignerName = computed(() => {
		const id = activeSignRequestId.value
		if (id === null) {
			return ''
		}
		return signerLabel(signerList.value.find((signer) => signer.signRequestId === id))
	})
	const mode = computed<'browse' | 'review' | 'place' | 'edit'>(() => {
		if (placing.value) {
			return 'place'
		}
		if (editMode.value) {
			return 'edit'
		}
		if (focusedSignerId.value !== null) {
			return 'review'
		}
		return 'browse'
	})
	// read-only drives the pdf-elements library: true → boxes are inert and the
	// page scrolls. This MUST match the authenticated editor (useVisibleElementsNext):
	//  - placing is page-level, so it works read-only;
	//  - mobile needs an explicit Edit toggle (a finger can't both drag a box and scroll);
	//  - desktop stays interactive so EXISTING boxes can be selected/moved directly.
	// The previous flat `!editMode` left desktop permanently read-only (editMode is
	// never set on desktop — the only toggle is the hidden banner control), so saved
	// elements could be displayed but never edited.
	const pdfReadOnly = computed(() => {
		if (placing.value) {
			return true
		}
		if (isMobile.value) {
			return !editMode.value
		}
		return false
	})
	const canSave = computed(() => documentFiles.value.length > 0)

	const overlappingSignerIds = computed<Set<number>>(() => {
		const ids = new Set<number>()
		overlaps.value.forEach((pair) => {
			ids.add(pair.a.signRequestId)
			ids.add(pair.b.signRequestId)
		})
		return ids
	})
	const overlapText = computed(() => (overlaps.value.length > 0
		? t('libresign', 'Some signature boxes overlap — they may collide.')
		: ''))
	const emptySignerNames = computed(() => {
		const empties = new Set(emptySigners.value)
		return signerList.value.filter((signer) => empties.has(signer.signRequestId)).map(signerLabel)
	})
	const anyEmpty = computed(() => emptySigners.value.length > 0)
	const partialEmpty = computed(() => placedCount.value > 0 && anyEmpty.value)
	const emptyWarning = computed(() => {
		const names = emptySignerNames.value
		if (names.length === 0) {
			return ''
		}
		return t('libresign', 'No signature position yet for: {names}', { names: names.join(', ') })
	})

	function isSelectedSigner(signer: SignerSummaryRecord): boolean {
		if (!signerSelected.value) {
			return false
		}
		return idsMatch(signer.signRequestId, signerSelected.value.signRequestId)
	}

	// --- page rail (parity with the authenticated editor) ---
	const railPages = computed<RailPage[]>(() => {
		const pages = Object.keys(filePagesMap.value)
			.map((key) => Number(key))
			.sort((a, b) => a - b)
		return pages.map((globalPage) => {
			const info = filePagesMap.value[globalPage]
			return {
				globalPage,
				docIndex: info.fileIndex,
				localPageIndex: globalPage - info.startPage,
				fileName: info.fileName,
			}
		})
	})
	function railColorFor(signRequestId: number): string {
		return colorFor(signRequestId).base
	}
	function scrollPageIntoView(docIndex: number, localPageIndex: number): void {
		const editorEl = (getPdfEditor() as unknown as { $el?: HTMLElement })?.$el
		if (!editorEl) {
			return
		}
		const wrappers = editorEl.querySelectorAll('.page-wrapper')
		let flatIndex = 0
		for (let d = 0; d < docIndex; d++) {
			flatIndex += (documentFiles.value[d]?.metadata?.p || 0)
		}
		flatIndex += localPageIndex
		const target = wrappers[flatIndex] as HTMLElement | undefined
		target?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
	}
	async function onSelectPage(docIndex: number, localPageIndex: number, globalPage: number): Promise<void> {
		currentGlobalPage.value = globalPage
		const pdfElements = getPdfElements()
		if (typeof pdfElements?.selectPage === 'function') {
			pdfElements.selectPage(docIndex, localPageIndex)
		} else if (pdfElements) {
			pdfElements.selectedDocIndex = docIndex
		}
		await nextTick()
		scrollPageIntoView(docIndex, localPageIndex)
	}

	async function loadPdfEditorFiles(): Promise<void> {
		const urls = pdfFiles.value.filter((url): url is string => typeof url === 'string' && url.length > 0)
		if (urls.length === 0) {
			pdfEditorFiles.value = []
			return
		}
		const blobs: PdfInput[] = []
		for (const [index, url] of urls.entries()) {
			const response = await fetch(url)
			const contentType = response.headers.get('Content-Type') ?? ''
			if (!response.ok || contentType.includes('application/json')) {
				showError(t('libresign', 'Document not found'))
				pdfEditorFiles.value = []
				return
			}
			const blob = await response.blob()
			blobs.push(new File([blob], pdfFileNames.value[index] || `document-${index + 1}.pdf`, { type: 'application/pdf' }))
		}
		pdfEditorFiles.value = blobs
	}

	function buildFilePagesMap(): void {
		filePagesMap.value = serialiseFilePagesMap(documentFiles.value)
	}
	function seedPlacements(): void {
		seed(serialiseSeedRecords(visibleElementsDocument.value, filePagesMap.value))
	}

	async function updateSigners(): Promise<void> {
		if (elementsLoaded.value || documentFiles.value.length === 0) {
			return
		}
		const shouldSkipRestore = signerSelected.value || getPdfElements()?.isAddingMode
		if (!shouldSkipRestore) {
			const pdfElements = getPdfElements()
			const pdfEditorRef = getPdfEditor()
			const elementsByDoc = serialiseRestorePlan(visibleElementsDocument.value, visibleElementsFiles.value, documentFiles.value)
			for (const [docIndex, items] of elementsByDoc.entries()) {
				if (typeof pdfElements?.selectPage === 'function') {
					pdfElements.selectPage(docIndex, 0)
				} else if (pdfElements) {
					pdfElements.selectedDocIndex = docIndex
				}
				await nextTick()
				await nextTick()
				await Promise.all(items.map(({ element, signer }) =>
					pdfEditorRef?.addSigner?.(signer, element, { documentIndex: docIndex }) ?? Promise.resolve()))
			}
		}
		elementsLoaded.value = true
	}

	async function open(): Promise<void> {
		loading.value = true
		pdfReady.value = false
		elementsLoaded.value = false
		editMode.value = false
		highlightId.value = null
		focusedSignerId.value = null
		placing.value = false
		signerSelected.value = null
		currentGlobalPage.value = 1
		resetColors()
		resetPlacements()
		modal.value = true
		await loadPdfEditorFiles()
		buildFilePagesMap()
		registerKeys(signerList.value.map((signer) => signer.signRequestId))
		seedPlacements()
		loading.value = false
	}

	function closeModal(): void {
		modal.value = false
		pdfReady.value = false
		elementsLoaded.value = false
		pdfEditorFiles.value = []
		focusedSignerId.value = null
		placing.value = false
		signerSelected.value = null
		editMode.value = false
		getPdfEditor()?.cancelAdding?.()
	}

	async function handlePdfReady(): Promise<void> {
		try {
			await updateSigners()
			await nextTick()
			markEditorReady()
		} finally {
			pdfReady.value = true
		}
	}

	function stopAddSigner(): void {
		getPdfEditor()?.cancelAdding?.()
		signerSelected.value = null
		placing.value = false
	}
	function toggleEdit(): void {
		editMode.value = !editMode.value
	}
	function onShowAll(): void {
		focusedSignerId.value = null
	}
	function onSheetDone(): void {
		editMode.value = false
		focusedSignerId.value = null
		highlightId.value = null
		syncFromEditor()
	}
	function handleObjectClick(): void {
		syncFromEditor()
	}
	function handleAddingEnded(event: { reason: 'placed' | 'cancelled' }): void {
		stopAddSigner()
		syncFromEditor()
		if (event.reason === 'placed') {
			showSuccess(t('libresign', 'Signature position added'))
		}
	}

	function onSelectSigner(signer: SignerSummaryRecord): void {
		if (!pdfReady.value) {
			return
		}
		const pdfEditorRef = getPdfEditor()
		if (!pdfEditorRef) {
			return
		}
		focusedSignerId.value = null
		if (isMobile.value) {
			const placedId = pdfEditorRef.dropSignerAtView?.(signer, { width: width.value, height: height.value }, MOBILE_SHEET_PEEK_INSET)
			if (placedId) {
				syncFromEditor()
				focusedSignerId.value = signer.signRequestId
				highlightId.value = placedId
				editMode.value = true
				showSuccess(t('libresign', 'Signature position added, drag the box to adjust'))
			}
			return
		}
		signerSelected.value = signer
		placing.value = true
		const started = pdfEditorRef.startAddingSigner?.(signer, {
			width: Math.round(width.value * DESKTOP_BOX_SCALE),
			height: Math.round(height.value * DESKTOP_BOX_SCALE),
		})
		if (!started) {
			signerSelected.value = null
			placing.value = false
		}
	}

	function onReviewSigner(signer: SignerSummaryRecord): void {
		if (!pdfReady.value) {
			return
		}
		if (placing.value) {
			stopAddSigner()
		}
		focusedSignerId.value = focusedSignerId.value === signer.signRequestId ? null : signer.signRequestId
	}

	// The editor removes the box from its own object list before emitting this,
	// so we only persist the removal via the PUBLIC delete action, then re-sync
	// counts. On failure we surface an error (the server still holds the element,
	// which reappears on the next reload); we never silently drop unsaved work.
	async function handleDeleteSigner(visibleElement: { elementId?: number } | null | undefined): Promise<void> {
		syncFromEditor()
		const elementId = visibleElement?.elementId
		if (elementId === undefined || elementId === null) {
			return
		}
		try {
			await store.deleteVisibleElement(Number(elementId))
		} catch {
			showError(store.errorMessage.value || t('libresign', 'Could not remove the signature position'))
		}
	}

	function buildVisibleElements() {
		return serialiseVisibleElements({
			files: documentFiles.value,
			filePagesMap: filePagesMap.value,
			getObjects: (docIndex: number) => getPdfElements()?.getAllObjects(docIndex) || [],
		})
	}

	async function save(): Promise<boolean> {
		loading.value = true
		try {
			await store.saveVisibleElements(buildVisibleElements())
			showSuccess(t('libresign', 'Signature positions saved'))
			// Close the editor on a successful save; the shell reflects the saved
			// placements from the hydrated store document.
			closeModal()
			return true
		} catch (error) {
			showError(store.errorMessage.value || t('libresign', 'An error occurred'))
			return false
		} finally {
			loading.value = false
		}
	}

	function setMobile(value: boolean): void {
		isMobile.value = value
	}

	return {
		// state
		pdfEditor,
		modal,
		loading,
		pdfReady,
		mode,
		editMode,
		focusedSignerId,
		placing,
		highlightId,
		isMobile,
		// document / editor inputs
		document,
		documentFiles,
		pdfEditorFiles,
		pdfFileNames,
		pdfReadOnly,
		pdfEditorSigners,
		colorMap,
		overlappingIds,
		// signer presentation
		signerList,
		signerCount,
		placedCount,
		countBySigner,
		signersByPage,
		// page rail
		railPages,
		currentGlobalPage,
		railColorFor,
		onSelectPage,
		placements,
		activeSignRequestId,
		activeSignerName,
		anyEmpty,
		partialEmpty,
		emptyWarning,
		overlapText,
		overlappingSignerIds,
		canSave,
		colorFor,
		isSelectedSigner,
		// lifecycle / interaction
		open,
		closeModal,
		handlePdfReady,
		handleObjectClick,
		handleAddingEnded,
		handleDeleteSigner,
		onSelectSigner,
		onReviewSigner,
		onShowAll,
		onSheetDone,
		stopAddSigner,
		toggleEdit,
		save,
		buildVisibleElements,
		setMobile,
		t,
	}
}
