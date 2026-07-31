/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Load + infinite-scroll pagination for the Files list (mirrors the legacy
 * VirtualList's IntersectionObserver approach), plus manual reload, the "New"
 * redirect, and the ?uuid= deep-link. Reads the store; never mutates its data
 * beyond calling its own load/reset actions.
 */
import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import type { Router } from 'vue-router'

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { t } from '@nextcloud/l10n'

import { showInfo } from '../../../services/toast'

type SidebarStoreLike = { activeRequestSignatureTab: () => void }

type Options = {
	/** The files Pinia store (loosely typed to avoid duplicating its shape). */
	filesStore: any
	router: Router
	sidebarStore: SidebarStoreLike
	sentinel: Ref<HTMLElement | null>
	/** Called after a filter change refetches the list (to drop stale selection). */
	onFiltersChanged: () => void
	/** Called once the first page has loaded (to clear the app-shell loading flag). */
	onInitialLoaded: () => void
}

export function usePagination(opts: Options) {
	const { filesStore, router, sidebarStore, sentinel } = opts
	let observer: IntersectionObserver | null = null

	function loadNextIfIdle() {
		if (filesStore.loading) {
			window.setTimeout(loadNextIfIdle, 100)
			return
		}
		if (!filesStore.loadedAll) {
			filesStore.getAllFiles()
		}
	}
	function updateObserver() {
		if (!sentinel.value || !observer) {
			return
		}
		observer.disconnect()
		observer.observe(sentinel.value)
	}
	function onFilesUpdated() {
		updateObserver()
	}
	function onFiltersUpdated() {
		opts.onFiltersChanged()
	}

	/** Manual reload — refetch from the first page, then confirm. */
	async function refresh() {
		await filesStore.updateAllFiles()
		showInfo(t('libresign', 'Documents refreshed'))
	}

	/** New document — reset the store and redirect to the request flow (upstream
	 * resets so the request page doesn't inherit the current list). */
	function goNew() {
		// Stop the observer first: $reset() empties the list and clears loadedAll,
		// which would otherwise trip the sentinel into refetching as we leave.
		observer?.disconnect()
		filesStore.selectFile()
		filesStore.$reset()
		router.replace({ name: 'requestFiles' })
	}

	/** Open the sidebar for a ?uuid= deep link, mirroring the legacy list. */
	async function openFromUuidQuery() {
		const query = router.currentRoute.value.query as { uuid?: string | string[] }
		const uuid = Array.isArray(query.uuid) ? query.uuid[0] : query.uuid
		if (!uuid) {
			return
		}
		const fileId = await filesStore.selectFileByUuid(uuid)
		if (fileId) {
			sidebarStore.activeRequestSignatureTab()
		}
	}

	onMounted(async () => {
		observer = new IntersectionObserver(([entry]) => {
			if (entry && entry.isIntersecting) {
				loadNextIfIdle()
			}
		})
		subscribe('libresign:files:updated', onFilesUpdated)
		subscribe('libresign:filters:update', onFiltersUpdated)

		await filesStore.getAllFiles({ force_fetch: true })
		opts.onInitialLoaded()
		updateObserver()
		openFromUuidQuery()
	})

	onBeforeUnmount(() => {
		observer?.disconnect()
		unsubscribe('libresign:files:updated', onFilesUpdated)
		unsubscribe('libresign:filters:update', onFiltersUpdated)
		filesStore.$reset()
	})

	return { refresh, goNew }
}
