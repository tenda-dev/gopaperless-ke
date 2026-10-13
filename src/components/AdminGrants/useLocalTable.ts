/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch, type Ref } from 'vue'

export interface LocalTableOptions<T> {
	/** Full, already-loaded row set (no server round-trips here). */
	rows: Ref<readonly T[]>
	pageSize?: number
	/** Predicate for the per-table search; term is pre-lowercased/trimmed. */
	match?: (row: T, term: string) => boolean
}

/**
 * Client-side search + pagination over an already-loaded array. Purely local:
 * it never fetches. Page resets to 1 when the term, page size, or the row set
 * changes, and is clamped when the filtered set shrinks.
 */
export function useLocalTable<T>(opts: LocalTableOptions<T>) {
	const term = ref('')
	const page = ref(1)
	const pageSize = ref(opts.pageSize ?? 10)

	const filtered = computed<readonly T[]>(() => {
		const q = term.value.trim().toLowerCase()
		if (!q || !opts.match) {
			return opts.rows.value
		}
		return opts.rows.value.filter((r) => opts.match!(r, q))
	})

	const total = computed(() => opts.rows.value.length)
	const filteredTotal = computed(() => filtered.value.length)
	const pageCount = computed(() => Math.max(1, Math.ceil(filteredTotal.value / pageSize.value)))

	const pageRows = computed<readonly T[]>(() => {
		const start = (page.value - 1) * pageSize.value
		return filtered.value.slice(start, start + pageSize.value)
	})

	const rangeStart = computed(() => (filteredTotal.value === 0 ? 0 : (page.value - 1) * pageSize.value + 1))
	const rangeEnd = computed(() => Math.min(page.value * pageSize.value, filteredTotal.value))

	watch([term, pageSize, () => opts.rows.value], () => { page.value = 1 })
	watch(pageCount, (pc) => { if (page.value > pc) { page.value = pc } })

	function setPage(p: number) { page.value = Math.min(Math.max(1, p), pageCount.value) }
	function setPageSize(s: number) { pageSize.value = s }
	function clear() { term.value = '' }

	return { term, page, pageSize, filtered, total, filteredTotal, pageCount, pageRows, rangeStart, rangeEnd, setPage, setPageSize, clear }
}
