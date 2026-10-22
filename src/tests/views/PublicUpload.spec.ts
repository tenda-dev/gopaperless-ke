/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

import PublicUpload from '../../views/PublicUpload.vue'
import PublicLegal from '../../components/PublicLegal.vue'
import PublicUploadEcosystem from '../../components/PublicUploadEcosystem.vue'
import PublicUploadHeader from '../../components/PublicUploadHeader.vue'
import PublicUploadHero from '../../components/PublicUploadHero.vue'

const replaceMock = vi.fn()
const createWorkflowMock = vi.fn()
const uploadDocumentMock = vi.fn()
const getWorkflowMock = vi.fn()

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => null),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, unknown>) =>
		text.replace(/\{(\w+)\}/g, (_m, k) => String(params?.[k] ?? '')),
}))

const loadStateMock = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args: unknown[]) => loadStateMock(...args),
}))

vi.mock('@/services/publicWorkflow', () => ({
	createWorkflow: (...args: unknown[]) => createWorkflowMock(...args),
	uploadDocument: (...args: unknown[]) => uploadDocumentMock(...args),
	getWorkflow: (...args: unknown[]) => getWorkflowMock(...args),
}))

vi.mock('vue-router', async () => {
	const actual = await vi.importActual<typeof import('vue-router')>('vue-router')
	return {
		...actual,
		useRouter: () => ({
			replace: replaceMock,
		}),
	}
})

/** A PDF File with a controllable size (File#size is read-only in jsdom). */
function pdf(sizeBytes = 1024): File {
	const file = new File(['x'], 'doc.pdf', { type: 'application/pdf' })
	Object.defineProperty(file, 'size', { value: sizeBytes })
	return file
}

/** A change-event whose target.files is the given file (or empty for a cancel). */
function fileEvent(file: File | null): Event {
	const input = document.createElement('input')
	Object.defineProperty(input, 'files', { value: file ? [file] : [], configurable: true })
	return { target: input } as unknown as Event
}

/** Capture window.location.href assignments. */
function spyLocationHref(): ReturnType<typeof vi.fn> {
	const hrefSetter = vi.fn()
	Object.defineProperty(window.location, 'href', { configurable: true, set: hrefSetter })
	return hrefSetter
}

describe('PublicUpload', () => {
	beforeEach(() => {
		replaceMock.mockReset()
		createWorkflowMock.mockReset().mockResolvedValue({})
		uploadDocumentMock.mockReset().mockResolvedValue({ fileUuid: 'uuid' })
		// Default: no resumable workflow (the status probe rejects, like a 401).
		getWorkflowMock.mockReset().mockRejectedValue(new Error('401'))
		loadStateMock.mockReset()
		loadStateMock.mockImplementation((_app: string, _key: string, fallback: unknown) => fallback)
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: vi.fn().mockImplementation((query: string) => ({
				matches: query === '(prefers-reduced-motion:reduce)',
				media: query,
			})),
		})
	})

	it('renders the landing sections for anonymous visitors', () => {
		const wrapper = shallowMount(PublicUpload)

		expect(wrapper.findComponent(PublicUploadHeader).exists()).toBe(true)
		expect(wrapper.findComponent(PublicUploadHero).exists()).toBe(true)
		expect(wrapper.findComponent(PublicUploadEcosystem).exists()).toBe(true)
	})

	it('shows the document instead of the hero on a legal page, even to a signed-in user', async () => {
		const { getCurrentUser } = await import('@nextcloud/auth')
		vi.mocked(getCurrentUser).mockReturnValueOnce({ uid: 'jane' } as ReturnType<typeof getCurrentUser>)

		const wrapper = shallowMount(PublicUpload, { props: { document: 'terms' } })

		expect(wrapper.findComponent(PublicUploadHero).exists()).toBe(false)
		expect(wrapper.findComponent(PublicLegal).props('document')).toBe('terms')
		expect(wrapper.findComponent(PublicUploadEcosystem).exists()).toBe(true)
		expect(replaceMock).not.toHaveBeenCalled()
	})

	it('navigates to login when get-started is emitted and no OIDC provider is configured', () => {
		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHero).vm.$emit('get-started')

		expect(hrefSetter).toHaveBeenCalledWith('/login?redirect_url=%2Fapps%2Flibresign%2Ff%2Frequest')
	})

	it('navigates to the configured OIDC login URL when get-started is emitted', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'public_upload_oidc_login_url') {
				return '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest'
			}
			return fallback
		})

		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHero).vm.$emit('get-started')

		expect(hrefSetter).toHaveBeenCalledWith('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest')
	})

	it('navigates to the configured OIDC login URL when sign-in is emitted', () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) => {
			if (key === 'public_upload_oidc_login_url') {
				return '/apps/user_oidc/login/1?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest'
			}
			return fallback
		})

		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		wrapper.findComponent(PublicUploadHeader).vm.$emit('sign-in')

		expect(hrefSetter).toHaveBeenCalledWith('/apps/user_oidc/login/1?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest')
	})

	// --- Anonymous upload entry (public_workflow_enabled) ---

	it('keeps the login-first CTA (flag off): the hero is told the workflow is disabled', () => {
		const wrapper = shallowMount(PublicUpload)
		expect(wrapper.findComponent(PublicUploadHero).props('workflowEnabled')).toBe(false)
	})

	it('flag on: the CTA opens the native file picker and creates nothing yet', async () => {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) =>
			(key === 'public_workflow_enabled' ? true : fallback))

		const wrapper = shallowMount(PublicUpload)
		expect(wrapper.findComponent(PublicUploadHero).props('workflowEnabled')).toBe(true)

		const input = wrapper.find('input[type="file"]').element as HTMLInputElement
		const clickSpy = vi.spyOn(input, 'click').mockImplementation(() => {})

		;(wrapper.vm as unknown as { openFilePicker: () => void }).openFilePicker()

		expect(clickSpy).toHaveBeenCalledOnce()
		expect(createWorkflowMock).not.toHaveBeenCalled()
	})

	it('cancelling the picker creates no workflow', async () => {
		const wrapper = shallowMount(PublicUpload)
		await (wrapper.vm as unknown as { onFilePicked: (e: Event) => Promise<void> }).onFilePicked(fileEvent(null))
		expect(createWorkflowMock).not.toHaveBeenCalled()
	})

	it('rejects a non-PDF before creating a workflow', async () => {
		const wrapper = shallowMount(PublicUpload)
		const bad = new File(['x'], 'doc.png', { type: 'image/png' })
		await (wrapper.vm as unknown as { onFilePicked: (e: Event) => Promise<void> }).onFilePicked(fileEvent(bad))
		expect(createWorkflowMock).not.toHaveBeenCalled()
		expect((wrapper.vm as unknown as { uploadError: string }).uploadError).not.toBe('')
	})

	it('rejects an oversized PDF before creating a workflow', async () => {
		const wrapper = shallowMount(PublicUpload)
		await (wrapper.vm as unknown as { onFilePicked: (e: Event) => Promise<void> }).onFilePicked(fileEvent(pdf(26 * 1024 * 1024)))
		expect(createWorkflowMock).not.toHaveBeenCalled()
		expect((wrapper.vm as unknown as { uploadError: string }).uploadError).not.toBe('')
	})

	it('on success: one workflow, one upload, then navigate to the workflow', async () => {
		const hrefSetter = spyLocationHref()
		const wrapper = shallowMount(PublicUpload)

		await (wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }).beginAnonymousUpload(pdf())

		expect(createWorkflowMock).toHaveBeenCalledOnce()
		expect(uploadDocumentMock).toHaveBeenCalledOnce()
		expect(hrefSetter).toHaveBeenCalledWith('/apps/libresign/p/workflow')
	})

	it('workflow-creation failure: stays on the landing with a retryable error', async () => {
		const hrefSetter = spyLocationHref()
		createWorkflowMock.mockRejectedValueOnce(new Error('nope'))

		const wrapper = shallowMount(PublicUpload)
		await (wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }).beginAnonymousUpload(pdf())

		expect(uploadDocumentMock).not.toHaveBeenCalled()
		expect(hrefSetter).not.toHaveBeenCalled()
		expect((wrapper.vm as unknown as { uploadError: string }).uploadError).not.toBe('')
		expect((wrapper.vm as unknown as { preparing: boolean }).preparing).toBe(false)
	})

	it('upload failure after creation: recovers by navigating into the workflow page', async () => {
		const hrefSetter = spyLocationHref()
		uploadDocumentMock.mockRejectedValueOnce(new Error('nope'))

		const wrapper = shallowMount(PublicUpload)
		await (wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }).beginAnonymousUpload(pdf())

		expect(createWorkflowMock).toHaveBeenCalledOnce()
		expect(hrefSetter).toHaveBeenCalledWith('/apps/libresign/p/workflow')
	})

	it('ignores a concurrent second submission (no duplicate workflow)', async () => {
		spyLocationHref()
		let resolveCreate: (v: unknown) => void = () => {}
		createWorkflowMock.mockImplementation(() => new Promise((resolve) => { resolveCreate = resolve }))

		const wrapper = shallowMount(PublicUpload)
		const vm = wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }
		const first = vm.beginAnonymousUpload(pdf())
		const second = vm.beginAnonymousUpload(pdf())
		resolveCreate({})
		await Promise.all([first, second])

		expect(createWorkflowMock).toHaveBeenCalledOnce()
	})

	// --- Resume an existing draft (reuses GET /public-workflow) ---

	function enableWorkflowFlag(): void {
		loadStateMock.mockImplementation((_app: string, key: string, fallback: unknown) =>
			(key === 'public_workflow_enabled' ? true : fallback))
	}

	it('does not probe for a resumable draft when the flag is disabled', async () => {
		shallowMount(PublicUpload)
		await flushPromises()
		expect(getWorkflowMock).not.toHaveBeenCalled()
	})

	it('does not probe when a signed-in user lands (claiming stays in the claim dialog)', async () => {
		enableWorkflowFlag()
		const { getCurrentUser } = await import('@nextcloud/auth')
		vi.mocked(getCurrentUser)
			.mockReturnValueOnce({ uid: 'jane' } as ReturnType<typeof getCurrentUser>)
			.mockReturnValueOnce({ uid: 'jane' } as ReturnType<typeof getCurrentUser>)

		shallowMount(PublicUpload)
		await flushPromises()

		expect(getWorkflowMock).not.toHaveBeenCalled()
	})

	it('no resumable workflow: the hero stays in upload mode', async () => {
		enableWorkflowFlag()
		const wrapper = shallowMount(PublicUpload)
		await flushPromises()

		expect(getWorkflowMock).toHaveBeenCalledOnce()
		expect(wrapper.findComponent(PublicUploadHero).props('resumable')).toBe(false)
	})

	it('resumable workflow: the hero offers to resume', async () => {
		enableWorkflowFlag()
		getWorkflowMock.mockResolvedValueOnce({ status: 'active', hasDocument: true })

		const wrapper = shallowMount(PublicUpload)
		await flushPromises()

		expect(wrapper.findComponent(PublicUploadHero).props('resumable')).toBe(true)
	})

	it('resume navigates to /p/workflow without creating or uploading', async () => {
		enableWorkflowFlag()
		getWorkflowMock.mockResolvedValueOnce({ status: 'active', hasDocument: true })
		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		await flushPromises()
		wrapper.findComponent(PublicUploadHero).vm.$emit('resume')

		expect(hrefSetter).toHaveBeenCalledWith('/apps/libresign/p/workflow')
		expect(createWorkflowMock).not.toHaveBeenCalled()
		expect(uploadDocumentMock).not.toHaveBeenCalled()
	})

	it('upload-new still works when a resumable draft exists', async () => {
		enableWorkflowFlag()
		getWorkflowMock.mockResolvedValueOnce({ status: 'active', hasDocument: true })
		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		await flushPromises()
		await (wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }).beginAnonymousUpload(pdf())

		expect(createWorkflowMock).toHaveBeenCalledOnce()
		expect(uploadDocumentMock).toHaveBeenCalledOnce()
		expect(hrefSetter).toHaveBeenCalledWith('/apps/libresign/p/workflow')
	})

	it('a failed status probe leaves the upload flow working', async () => {
		enableWorkflowFlag()
		getWorkflowMock.mockRejectedValueOnce(new Error('boom'))
		const hrefSetter = spyLocationHref()

		const wrapper = shallowMount(PublicUpload)
		await flushPromises()
		expect(wrapper.findComponent(PublicUploadHero).props('resumable')).toBe(false)

		await (wrapper.vm as unknown as { beginAnonymousUpload: (f: File) => Promise<void> }).beginAnonymousUpload(pdf())
		expect(createWorkflowMock).toHaveBeenCalledOnce()
		expect(hrefSetter).toHaveBeenCalledWith('/apps/libresign/p/workflow')
	})
})
