/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Wraps dynamically imported components with the shared GoPaperless loading
 * and recoverable-error UI.
 *
 * Uses the shared retry policy while loading and exposes a manual retry when
 * automatic attempts are exhausted. The returned component forwards props,
 * attributes and listeners to the loaded component, preserving the wrapped
 * component's usage contract.
 *
 * GpLoader uses native markup and CSS, avoiding a dependency on Nextcloud
 * components or styles during application startup.
 */
import { defineComponent, h, onErrorCaptured, ref, shallowRef, type AsyncComponentLoader } from 'vue'
import GpLoader from '../components/Common/GpLoader.vue'
import { asyncRetry } from './asyncRetry'

export function asyncLoader(loader: AsyncComponentLoader) {
	return defineComponent({
		name: 'AsyncLoader',
		// Render either GpLoader or the wrapped component as the root, so attrs and
		// listeners must be forwarded explicitly.
		inheritAttrs: false,
		setup(_props, { attrs, slots }) {
			const failed = ref(false)

			// Create a new async component for each attempt because Vue caches a rejected
			// loader on its component definition. Rebuilding it allows the chunk to be fetched again.
			const build = () => asyncRetry(loader, {
				loadingComponent: GpLoader,
				onFinalError: () => { failed.value = true },
			})
			const inner = shallowRef(build())

			const retry = () => {
				failed.value = false
				inner.value = build()
			}

			// Suppress the load failure already handled by the error state, but allow
			// runtime errors from the loaded component to propagate.
			onErrorCaptured(() => (failed.value ? false : undefined))

			return () => (failed.value
				? h(GpLoader, { state: 'error', onRetry: retry })
				: h(inner.value, { ...attrs }, slots))
		},
	})
}
