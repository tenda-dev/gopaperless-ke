/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { getCapabilities } from '@nextcloud/capabilities'
import { subscribe, unsubscribe, type Event as NextcloudEvent, type EventHandler } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, type ComponentPublicInstance } from 'vue'
import type { RailPage } from '../VePageRail.vue'
import { FILE_STATUS } from '../../../../constants.js'
import { useFilesStore } from '../../../../store/files.js'
import { useSignerColors } from './useSignerColors'
import { usePlacements, type PlacementRecord } from './usePlacements'
import {
	aggregateVisibleElementsByFiles,
	findFileById,
	getFileSigners,
	getFileUrl,
	getVisibleElementsFromDocument,
	idsMatch,
	normaliseEditableRequestFile,
	normaliseVisibleElement,
	normaliseVisibleElements,
	toSignerSummaryRecord,
	toVisibleElementsDocument,
	toVisibleElementsFile,
	type EditableRequestChildFile,
	type EditableRequestFile,
	type EditableRequestSigner,
	type EditableVisibleElementPayload,
	type PdfObject,
} from '../shared/visibleElementsShared'
import type {
	DocumentLike,
	FileLike,
} from '../../../../services/visibleElementsService'
import type {
	IdentifyMethodRecord,
	LibresignCapabilities,
	SignerSummaryRecord,
	VisibleElementRecord,
} from '../../../../types/index'
import { showError, showSuccess } from '@/services/toast.ts'

export function useVisibleElementsNext() {



type FilePageInfo = {
	id: number
	fileIndex: number
	startPage: number
	fileName: string
}

type PdfInput = string | Blob | ArrayBuffer | ArrayBufferView | Record<string, unknown>

type PdfElementsRef = {
	getAllObjects: (docIndex: number) => PdfObject[]
	selectPage?: (docIndex: number, pageIndex: number) => void
	selectedDocIndex?: number
	selectedPageIndex?: number
	isAddingMode?: boolean
}

type PdfEditorRef = ComponentPublicInstance & {
	$refs?: { pdfElements?: PdfElementsRef }
	startAddingSigner?: (signer: SignerSummaryRecord | null | undefined, size: { width?: number, height?: number }) => boolean
	dropSignerAtView?: (signer: SignerSummaryRecord | null | undefined, size: { width?: number, height?: number }, bottomInset?: number) => string | null
	cancelAdding?: () => void
	addSigner?: (signer: SignerSummaryRecord, visibleElement: VisibleElementRecord, options?: { documentIndex?: number }) => Promise<void>
}

type FilesStore = Pick<ReturnType<typeof useFilesStore>, 'loading' | 'getFile' | 'getEditableFile' | 'saveOrUpdateSignatureRequest'> & {
	loading: boolean
	getFile: ReturnType<typeof useFilesStore>['getFile']
	getEditableFile: ReturnType<typeof useFilesStore>['getEditableFile']
	saveOrUpdateSignatureRequest: (payload: { visibleElements: EditableVisibleElementPayload[] }) => Promise<{ message: string }>
}

type AddingEndedPayload =
	| { reason: 'placed', object: unknown, docIndex: number, pageIndex: number }
	| { reason: 'cancelled' }

const filesStore = useFilesStore() as FilesStore
const instance = getCurrentInstance()
const pdfEditor = ref<PdfEditorRef | null>(null)
const canRequestSign = ref(loadState('libresign', 'can_request_sign', false))
const modal = ref(false)
const loading = ref(false)
const signerSelected = ref<SignerSummaryRecord | null>(null)
const capabilities = getCapabilities() as LibresignCapabilities
const signElementsConfig = capabilities.libresign?.config['sign-elements'] ?? {
	'is-available': false,
	'full-signature-width': 0,
	'full-signature-height': 0,
}
const width = ref(signElementsConfig['full-signature-width'])
const height = ref(signElementsConfig['full-signature-height'])
const filePagesMap = ref<Record<number, FilePageInfo>>({})
const elementsLoaded = ref(false)
const pdfReady = ref(false)
const fetchedFiles = ref<EditableRequestChildFile[]>([])
const pdfEditorFiles = ref<PdfInput[]>([])
const currentGlobalPage = ref(1)

// Interaction state
// focusedSignerId: signer being isolated/reviewed (tap a card). placing: armed
// for tap-to-place. editMode: mobile-only explicit drag/resize toggle.
const focusedSignerId = ref<number | null>(null)
const placing = ref(false)
const editMode = ref(false)
const highlightId = ref<string | null>(null)
const showHelp = ref(false)

// Steps tailored to the platform (placement differs: desktop click-to-place,
// mobile drop-then-drag).
const helpSteps = computed<string[]>(() => {
	if (isMobile.value) {
		return [
			t('libresign', 'Scroll to the page where a signature should go.'),
			t('libresign', 'Tap a signer — their box drops onto the page you are viewing.'),
			t('libresign', 'Drag the box to position it, then tap Done.'),
			t('libresign', 'Drag the sheet up to review every signer; tap the eye to isolate one.'),
			t('libresign', 'Tap Save when finished — “Save anyway” if a signer has no position.'),
		]
	}
	return [
		t('libresign', 'Click a signer, then click on the document where their signature goes.'),
		t('libresign', 'Drag a box to move it; use the corner handles to resize.'),
		t('libresign', 'Click the eye on a signer to review just their positions (others dim).'),
		t('libresign', 'Overlapping boxes are flagged in red so signatures don’t collide.'),
		t('libresign', 'Click Save when finished — “Save anyway” if a signer has no position.'),
	]
})
const isMobile = ref(false)
let mobileQuery: MediaQueryList | null = null

// Peek height of the mobile sheet (keep in sync with VeSignerSheet PEEK_HEIGHT);
// used to align current-page detection to the area visible above the sheet.
const MOBILE_SHEET_PEEK_INSET = 208

// Desktop places a smaller default box (30% smaller) than the configured full
// size; mobile keeps the full size. Applies to newly-placed boxes only.
const DESKTOP_BOX_SCALE = 0.7

function syncIsMobile() {
	isMobile.value = Boolean(mobileQuery?.matches)
}

// Derived mode for the banner.
const mode = computed<'browse' | 'review' | 'place' | 'edit'>(() => {
	if (placing.value) {
		return 'place'
	}
	if (isMobile.value && editMode.value) {
		return 'edit'
	}
	if (focusedSignerId.value !== null) {
		return 'review'
	}
	return 'browse'
})

// read-only drives the library: true → boxes inert & the page scrolls (the
// mobile-scroll fix). Placing is page-level so it works read-only. Desktop can
// stay interactive (a mouse doesn't fight scroll); mobile needed an Edit toggle.
const pdfReadOnly = computed(() => {
	if (placing.value) {
		return true
	}
	if (isMobile.value) {
		return !editMode.value
	}
	return false
})

// Colours & placement model
const { colorMap, colorFor: paletteFor, registerKeys, reset: resetColors } = useSignerColors()

function toGlobalPage(docIndex: number, localPageNumber: number): number {
	for (const key of Object.keys(filePagesMap.value)) {
		const info = filePagesMap.value[Number(key)]
		if (info.fileIndex === docIndex) {
			return info.startPage + localPageNumber - 1
		}
	}
	return localPageNumber
}

function globalPageForFileLocal(fileId: number, localPage: number): number {
	for (const key of Object.keys(filePagesMap.value)) {
		const info = filePagesMap.value[Number(key)]
		if (info.id === fileId) {
			return info.startPage + localPage - 1
		}
	}
	return localPage
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

const document = computed<EditableRequestFile>(() => filesStore.getEditableFile())
const fallbackDocumentFile = computed<EditableRequestChildFile | null>(() => {
	const documentUrl = typeof (document.value as { url?: unknown }).url === 'string'
		? (document.value as { url?: string }).url
		: null
	const pdfUrl = documentUrl
		|| (typeof document.value.uuid === 'string' && document.value.uuid.length > 0
			? generateUrl('/apps/libresign/p/pdf/{uuid}', { uuid: document.value.uuid })
			: null)
	const normalisedFile = normaliseEditableRequestFile({
		id: document.value.id,
		name: document.value.name,
		file: document.value.file ?? pdfUrl ?? null,
		metadata: document.value.metadata,
		signers: document.value.signers,
		visibleElements: document.value.visibleElements,
	})

	if (!normalisedFile) {
		return null
	}

	return getFileUrl(toVisibleElementsFile(normalisedFile)) ? normalisedFile : null
})

function hasRenderablePdfFiles(files: EditableRequestChildFile[]): boolean {
	return files.some((file) => getFileUrl(toVisibleElementsFile(file)) !== null)
}

const documentFiles = computed<EditableRequestChildFile[]>(() => {
	if (fetchedFiles.value.length > 0) {
		return fetchedFiles.value
	}

	if (Array.isArray(document.value.files) && document.value.files.length > 0 && hasRenderablePdfFiles(document.value.files)) {
		return document.value.files
	}

	return fallbackDocumentFile.value ? [fallbackDocumentFile.value] : []
})
const visibleElementsDocument = computed<DocumentLike>(() => toVisibleElementsDocument(document.value))
const visibleElementsFiles = computed<FileLike[]>(() => documentFiles.value.map(toVisibleElementsFile))

const signerList = computed<SignerSummaryRecord[]>(() => (Array.isArray(document.value.signers) ? document.value.signers : [])
	.map(toSignerSummaryRecord)
	.filter((signer): signer is SignerSummaryRecord => signer !== null))
const pdfEditorSigners = computed<SignerSummaryRecord[]>(() => signerList.value)
const signerCount = computed(() => signerList.value.length)

const status = computed(() => Number(document.value.status))
const isDraft = computed(() => status.value === FILE_STATUS.DRAFT)
const canSave = computed(() => ([FILE_STATUS.DRAFT, FILE_STATUS.ABLE_TO_SIGN, FILE_STATUS.PARTIAL_SIGNED] as number[]).includes(status.value))
const statusLabel = computed(() => document.value.statusText || '')
const statusColor = computed(() => {
	if (status.value === FILE_STATUS.ABLE_TO_SIGN || status.value === FILE_STATUS.SIGNED) {
		return '#04d56d'
	}
	if (isDraft.value) {
		return 'var(--color-warning, #ba7517)'
	}
	return 'var(--color-text-maxcontrast)'
})

const pdfFiles = computed<PdfInput[]>(() => visibleElementsFiles.value.flatMap((file) => {
	const fileUrl = getFileUrl(file)
	return fileUrl ? [fileUrl] : []
}))
const pdfFileNames = computed(() => documentFiles.value.map(file => `${file.name}.${file.metadata?.extension || 'pdf'}`))

// Derived UI state
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
	const signer = signerList.value.find((item) => item.signRequestId === id)
	return signer ? signerLabel(signer) : ''
})

const emptySignerNames = computed(() => {
	const empties = new Set(emptySigners.value)
	return signerList.value
		.filter((signer) => empties.has(signer.signRequestId))
		.map((signer) => signerLabel(signer))
})
const anyEmpty = computed(() => emptySigners.value.length > 0)
// Only (soft) alert about empties once placement has begun. A brand-new request with
// nothing placed yet should read neutral, not alarming.
const partialEmpty = computed(() => placedCount.value > 0 && anyEmpty.value)
const emptyWarning = computed(() => {
	if (emptySignerNames.value.length === 1) {
		return t('libresign', '{name} has no signature position', { name: emptySignerNames.value[0] })
	}
	return t('libresign', '{count} signers have no position yet: {names}', {
		count: emptySignerNames.value.length,
		names: emptySignerNames.value.join(', '),
	})
})

const overlapText = computed(() => {
	if (overlaps.value.length === 0) {
		return ''
	}
	const pair = overlaps.value[0]
	const nameOf = (signRequestId: number) => {
		const signer = signerList.value.find((item) => item.signRequestId === signRequestId)
		return signer ? signerLabel(signer) : t('libresign', 'a signer')
	}
	const more = overlaps.value.length > 1
		? ' ' + t('libresign', '(+{count} more)', { count: overlaps.value.length - 1 })
		: ''
	return t('libresign', '{a} and {b} overlap on page {page} — signatures may collide', {
		a: nameOf(pair.a.signRequestId),
		b: nameOf(pair.b.signRequestId),
		page: pair.globalPage,
	}) + more
})

// Signers that have at least one overlapping box → red row accent on mobile.
const overlappingSignerIds = computed<Set<number>>(() => {
	const ids = new Set<number>()
	overlaps.value.forEach((pair) => {
		ids.add(pair.a.signRequestId)
		ids.add(pair.b.signRequestId)
	})
	return ids
})

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

function colorFor(signRequestId: number | undefined | null) {
	return paletteFor(signRequestId)
}
function railColorFor(signRequestId: number): string {
	return paletteFor(signRequestId).base
}

function signerLabel(signer: SignerSummaryRecord | null | undefined): string {
	return signer?.displayName || signer?.email || t('libresign', 'Unnamed signer')
}

// Editor ref helpers (isolate internal-surface access here)
function getPdfEditor() {
	const instancePdfEditor = instance?.proxy?.$refs?.pdfEditor as PdfEditorRef | undefined
	return instancePdfEditor || pdfEditor.value
}
function getPdfElements() {
	return getPdfEditor()?.$refs?.pdfElements
}

function getOcsErrorMessage(error: unknown): string | null {
	if (typeof error !== 'object' || error === null || !('response' in error)) {
		return null
	}
	const response = (error as { response?: unknown }).response
	if (typeof response !== 'object' || response === null || !('data' in response)) {
		return null
	}
	const data = (response as { data?: unknown }).data
	if (typeof data !== 'object' || data === null || !('ocs' in data)) {
		return null
	}
	const ocs = (data as { ocs?: unknown }).ocs
	if (typeof ocs !== 'object' || ocs === null || !('data' in ocs)) {
		return null
	}
	const ocsData = (ocs as { data?: unknown }).data
	if (typeof ocsData !== 'object' || ocsData === null || !('message' in ocsData)) {
		return null
	}
	const message = (ocsData as { message?: unknown }).message
	return typeof message === 'string' ? message : null
}

function isSelectedSigner(signer: SignerSummaryRecord): boolean {
	if (!signerSelected.value) {
		return false
	}
	return idsMatch(signer.signRequestId, signerSelected.value.signRequestId)
}

async function showModal() {
	if (!canRequestSign.value) {
		return
	}
	if (signElementsConfig['is-available'] === false) {
		return
	}
	modal.value = true
	filesStore.loading = true
	pdfEditorFiles.value = []
	focusedSignerId.value = null
	placing.value = false
	editMode.value = false

	if (documentFiles.value.length === 0) {
		await fetchFiles()
	}

	pdfReady.value = false
	elementsLoaded.value = false

	await loadPdfEditorFiles()
	buildFilePagesMap()
	registerKeys(signerList.value.map((signer) => signer.signRequestId))
	seedPlacements()
	filesStore.loading = false
}

// Seed the placement model straight from the server visibleElements so counts
// are correct the instant the modal opens, before the editor restores boxes.
function seedPlacements() {
	const elements = getVisibleElementsFromDocument(visibleElementsDocument.value)
	const records: PlacementRecord[] = []
	elements.forEach((element) => {
		const normalised = normaliseVisibleElement(element)
		if (!normalised) {
			return
		}
		records.push({
			id: `seed-${normalised.elementId}`,
			signRequestId: normalised.signRequestId,
			globalPage: globalPageForFileLocal(normalised.fileId, Number(normalised.coordinates.page)),
			x: Number(normalised.coordinates.left),
			y: Number(normalised.coordinates.top),
			width: Number(normalised.coordinates.width ?? 0),
			height: Number(normalised.coordinates.height ?? 0),
		})
	})
	seed(records)
}

async function loadPdfEditorFiles() {
	const urls = pdfFiles.value.filter((file): file is string => typeof file === 'string' && file.length > 0)
	if (urls.length === 0) {
		pdfEditorFiles.value = []
		return
	}

	const blobs: File[] = []
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

async function fetchFiles() {
	const response = await axios.get(generateOcsUrl('/apps/libresign/api/v1/file/list'), {
		params: {
			parentFileId: document.value.id,
			details: true,
			force_fetch: true,
		},
	})
	const childFiles = response?.data?.ocs?.data?.data || []
	fetchedFiles.value = Array.isArray(childFiles)
		? childFiles.map(normaliseEditableRequestFile).filter((file): file is EditableRequestChildFile => file !== null)
		: []
	const currentFile = filesStore.getEditableFile()
	if (currentFile) {
		currentFile.files = fetchedFiles.value as typeof currentFile.files
	}

	const allVisibleElements = aggregateVisibleElementsByFiles(visibleElementsFiles.value)
	if (allVisibleElements.length > 0) {
		document.value.visibleElements = normaliseVisibleElements(allVisibleElements)
		return
	}

	const nestedDocumentElements = getVisibleElementsFromDocument(visibleElementsDocument.value)
	if (nestedDocumentElements.length > 0) {
		document.value.visibleElements = normaliseVisibleElements(nestedDocumentElements)
	}
}

function buildFilePagesMap() {
	filePagesMap.value = {}
	const filesToProcess = documentFiles.value
	let currentPage = 1
	filesToProcess.forEach((file, index) => {
		const pageCount = file.metadata?.p || 0
		const fileId = typeof file.id === 'number' ? file.id : Number(file.id)
		if (!Number.isFinite(fileId)) {
			currentPage += pageCount
			return
		}
		for (let pageIndex = 0; pageIndex < pageCount; pageIndex++) {
			filePagesMap.value[currentPage + pageIndex] = {
				id: fileId,
				fileIndex: index,
				startPage: currentPage,
				fileName: file.name ?? '',
			}
		}
		currentPage += pageCount
	})
}

function closeModal() {
	modal.value = false
	filesStore.loading = false
	pdfReady.value = false
	elementsLoaded.value = false
	fetchedFiles.value = []
	pdfEditorFiles.value = []
	focusedSignerId.value = null
	placing.value = false
	editMode.value = false
	highlightId.value = null
	resetColors()
	resetPlacements()
	getPdfEditor()?.cancelAdding?.()
	signerSelected.value = null
}

function getPageHeightForFile(fileId: number, page: number) {
	const filesToSearch = documentFiles.value
	const fileInfo = filesToSearch.find(file => file.id === fileId)
	return fileInfo?.metadata?.d?.[page - 1]?.h
}

async function updateSigners() {
	const filesToProcess = documentFiles.value

	if (elementsLoaded.value || filesToProcess.length === 0) {
		return
	}

	const shouldSkipRestore = signerSelected.value || getPdfElements()?.isAddingMode

	if (!shouldSkipRestore) {
		const pdfElements = getPdfElements()
		const pdfEditorRef = getPdfEditor()

		const fileIndexById = new Map<string, number>(filesToProcess.map((file, index) => [String(file.id), index]))
		const elements = getVisibleElementsFromDocument(visibleElementsDocument.value)
		const elementsByDoc = new Map<number, Array<{ element: VisibleElementRecord, signer: SignerSummaryRecord }>>()

		elements.forEach((element) => {
			const normalisedElement = normaliseVisibleElement(element)
			if (!normalisedElement) {
				return
			}
			const fileInfo = findFileById(visibleElementsFiles.value, normalisedElement.fileId)
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

		for (const [docIndex, items] of elementsByDoc.entries()) {
			if (typeof pdfElements?.selectPage === 'function') {
				pdfElements.selectPage(docIndex, 0)
			} else if (pdfElements) {
				pdfElements.selectedDocIndex = docIndex
				pdfElements.selectedPageIndex = 0
			}
			await nextTick()
			await nextTick()

			// Await restore so the editor's object list is populated before we
			// mark it the source of truth (fixes the "0 of N placed" race).
			await Promise.all(items.map(({ element, signer }) =>
				pdfEditorRef?.addSigner?.(signer, element, { documentIndex: docIndex }) ?? Promise.resolve()))
		}
	}

	elementsLoaded.value = true
	filesStore.loading = false
}

// Interaction handlers
// Desktop: legacy direct flow - tapping a signer arms placement; the next click
// on the page drops the box there. Mobile: tapping a signer drops the box at the
// centre of the current page and enters edit mode to drag it (avoids the touch
// tap-to-place / scroll conflict and the stale second-placement position).
function onSelectSigner(signer: SignerSummaryRecord) {
	if (!pdfReady.value) {
		return
	}
	const pdfEditorRef = getPdfEditor()
	if (!pdfEditorRef) {
		return
	}
	// Leave any review state; placement takes over.
	focusedSignerId.value = null

	if (isMobile.value) {
		// Offset the target-page detection by the peek sheet height so the box
		// drops on the page visible ABOVE the sheet, not one behind it.
		const placedId = pdfEditorRef.dropSignerAtView?.(signer, {
			width: width.value,
			height: height.value,
		}, MOBILE_SHEET_PEEK_INSET)
		if (placedId) {
			syncFromEditor()
			// Isolate (dim the others) and pulse the new box so it's obvious what
			// just appeared and where.
			focusedSignerId.value = signer.signRequestId
			highlightId.value = placedId
			editMode.value = true
			window.setTimeout(() => {
				if (highlightId.value === placedId) {
					highlightId.value = null
				}
			}, 2500)
			showSuccess(t('libresign', 'Signature position added, drag the signature box to adjust'))
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

// Review is a separate control (the eye button): isolate a signer's existing
// boxes without arming placement.
function onReviewSigner(signer: SignerSummaryRecord) {
	if (!pdfReady.value) {
		return
	}
	if (placing.value) {
		stopAddSigner()
	}
	if (focusedSignerId.value === signer.signRequestId) {
		focusedSignerId.value = null
		return
	}
	focusedSignerId.value = signer.signRequestId
	const first = placements.value.find((placement) => placement.signRequestId === signer.signRequestId)
	if (first) {
		navigateToGlobalPage(first.globalPage)
	}
}

function onShowAll() {
	focusedSignerId.value = null
}

function handleAddingEnded(event: AddingEndedPayload) {
	stopAddSigner()
	syncFromEditor()

	if (event.reason === 'placed') {
		showSuccess(t('libresign', 'Signature position added'))
	}
}

function stopAddSigner() {
	getPdfEditor()?.cancelAdding?.()
	signerSelected.value = null
	placing.value = false
	// Keep focusedSignerId so we return to reviewing that signer.
}

function toggleEdit() {
	editMode.value = !editMode.value
}

// Mobile sheet finished adjusting a dropped box switch back to scroll mode.
function onSheetDone() {
	editMode.value = false
	focusedSignerId.value = null
	highlightId.value = null
	syncFromEditor()
}

function handleObjectClick() {
	// A box was interacted with (select/drag); refresh derived state.
	syncFromEditor()
}

function navigateToGlobalPage(globalPage: number) {
	const page = railPages.value.find((item) => item.globalPage === globalPage)
	if (page) {
		void onSelectPage(page.docIndex, page.localPageIndex, page.globalPage)
	}
}

async function onSelectPage(docIndex: number, localPageIndex: number, globalPage: number) {
	currentGlobalPage.value = globalPage
	const pdfElements = getPdfElements()
	if (typeof pdfElements?.selectPage === 'function') {
		pdfElements.selectPage(docIndex, localPageIndex)
	} else if (pdfElements) {
		pdfElements.selectedDocIndex = docIndex
		pdfElements.selectedPageIndex = localPageIndex
	}
	await nextTick()
	scrollPageIntoView(docIndex, localPageIndex)
}

function scrollPageIntoView(docIndex: number, localPageIndex: number) {
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

async function onDeleteSigner(visibleElement: VisibleElementRecord) {
	if (!visibleElement?.elementId) {
		return
	}
	await axios.delete(generateOcsUrl('/apps/libresign/api/v1/file-element/{uuid}/{elementId}', {
		uuid: document.value.uuid,
		elementId: visibleElement.elementId,
	}))
}

function handleDeleteSigner(object: unknown) {
	const visibleElement = normaliseVisibleElement(object)
	syncFromEditor()
	if (!visibleElement) {
		return
	}
	void onDeleteSigner(visibleElement)
}

async function save() {
	loading.value = true
	const visibleElements = buildVisibleElements()

	try {
		await filesStore.saveOrUpdateSignatureRequest({ visibleElements })
		showSuccess(t('libresign', 'Signature positions saved successfully'))
		closeModal()
		return true
	} catch (error) {
		showError(getOcsErrorMessage(error) || t('libresign', 'An error occurred'))
		return false
	} finally {
		loading.value = false
	}
}

const handleShowVisibleElements: EventHandler<NextcloudEvent> = () => {
	void showModal()
}

// Ported verbatim from VisibleElements.vue. Do not alter the coordinate math
// or the payload shape.
function buildVisibleElements() {
	const visibleElements: EditableVisibleElementPayload[] = []
	const currentFiles = documentFiles.value
	const pdfElements = getPdfElements()
	const numDocuments = currentFiles.length

	for (let docIndex = 0; docIndex < numDocuments; docIndex++) {
		const objects = pdfElements?.getAllObjects(docIndex) || []
		objects.forEach((object: PdfObject) => {
			if (!object.signer) return

			let globalPageNumber = object.pageNumber
			const pageInfos = Object.keys(filePagesMap.value).map((page) => filePagesMap.value[Number(page)])
			for (const info of pageInfos) {
				if (info.fileIndex === docIndex) {
					globalPageNumber = info.startPage + object.pageNumber - 1
					break
				}
			}

			const pageInfo = filePagesMap.value[globalPageNumber]
			if (!pageInfo) {
				return
			}
			const pageHeight = getPageHeightForFile(pageInfo.id, object.pageNumber)
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

async function handlePdfReady() {
	try {
		await updateSigners()
		await nextTick()
		markEditorReady()
	} finally {
		pdfReady.value = true
	}
}

onMounted(() => {
	mobileQuery = window.matchMedia('(max-width: 767px)')
	syncIsMobile()
	mobileQuery.addEventListener?.('change', syncIsMobile)
	subscribe('libresign:show-visible-elements', handleShowVisibleElements)
})

onBeforeUnmount(() => {
	mobileQuery?.removeEventListener?.('change', syncIsMobile)
	unsubscribe('libresign:show-visible-elements', handleShowVisibleElements)
})

return {
	filesStore,
	pdfEditor,
	t,
	modal,
	loading,
	mode,
	focusedSignerId,
	placing,
	signerSelected,
	pdfReadOnly,
	document,
	documentFiles,
	pdfReady,
	pdfEditorFiles,
	pdfFiles,
	pdfFileNames,
	canSave,
	status,
	statusLabel,
	statusColor,
	isDraft,
	placedCount,
	countBySigner,
	signerList,
	signerCount,
	pdfEditorSigners,
	activeSignRequestId,
	activeSignerName,
	emptySignerNames,
	anyEmpty,
	partialEmpty,
	emptyWarning,
	overlapText,
	overlappingSignerIds,
	railPages,
	signersByPage,
	currentGlobalPage,
	colorFor,
	railColorFor,
	isSelectedSigner,
	colorMap,
	overlappingIds,
	highlightId,
	showHelp,
	helpSteps,
	isMobile,
	getPdfElements,
	showModal,
	fetchFiles,
	buildFilePagesMap,
	seedPlacements,
	closeModal,
	getPageHeightForFile,
	updateSigners,
	onSelectSigner,
	onReviewSigner,
	onShowAll,
	handleAddingEnded,
	stopAddSigner,
	toggleEdit,
	onSheetDone,
	handleObjectClick,
	onSelectPage,
	onDeleteSigner,
	handleDeleteSigner,
	save,
	buildVisibleElements,
	syncFromEditor,
	handlePdfReady,
}}
