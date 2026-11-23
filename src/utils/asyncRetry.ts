/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared async-component loading primitives for GoPaperless.
 *
 * Provides fetch-once and bounded-retry behaviour without feature-specific
 * or Nextcloud-component dependencies, allowing different application
 * surfaces to reuse the same loading infrastructure.
 */
import { defineAsyncComponent, type AsyncComponentLoader, type Component } from 'vue'

/**
 * Caches a dynamic import promise so concurrent callers share the same load.
 * A rejected import clears the cache, allowing a subsequent call to retry.
 */
export function once<T>(loader: () => Promise<T>): () => Promise<T> {
	let promise: Promise<T> | undefined
	return () => {
		if (!promise) {
			promise = loader().catch((error) => {
				promise = undefined
				throw error
			})
		}
		return promise
	}
}

export interface AsyncRetryOptions {
    /**
     * Component displayed while the chunk resolves. When omitted, Vue
     * preserves its default behaviour and renders no loading component.
     */
    loadingComponent?: Component

    /**
     * Called once after automatic retries are exhausted, before Vue surfaces
     * the failure. Allows callers to provide their own recovery UI.
     */
    onFinalError?: (error: unknown) => void
}

/**
 * Wraps an async component loader with a bounded retry policy.
 *
 * Retries transient loading failures twice before allowing Vue to surface
 * the final error. Optional hooks let callers customise the loading and
 * failure states without changing the shared retry behaviour.
 */
export function asyncRetry(loader: AsyncComponentLoader, options: AsyncRetryOptions = {}): Component {
	return defineAsyncComponent({
		loader,
		...(options.loadingComponent ? { loadingComponent: options.loadingComponent } : {}),
		onError(error, retry, fail, attempts) {
			if (attempts <= 2) {
				retry()
			} else {
				options.onFinalError?.(error)
				fail()
			}
		},
	})
}
