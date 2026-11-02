/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Owns the dashboard's data: the selected range and one independent state
 * machine per panel (loading / ready / empty / error), so one failing endpoint
 * only errors its own panel. A newer request supersedes an older one, so a slow
 * response for a previous range can never overwrite the current view.
 */
import { computed, reactive, ref } from 'vue'

import { OperationsApiError, operationsApi } from '../api.ts'
import type {
	EntitlementsResponse,
	FunnelResponse,
	OverviewResponse,
	PanelState,
	PaymentsResponse,
	RangeParams,
	RangePreset,
	TimeseriesResponse,
} from '../types.ts'
import { eatDate } from '../utils/format.ts'

const TZ = 'Africa/Nairobi'
const MAX_DAYS = 366

type PanelKey = 'overview' | 'timeseries' | 'funnel' | 'payments' | 'entitlements'

function panel<T>(): PanelState<T> {
	return { status: 'loading', data: null, error: null }
}

export function useOperationsData() {
	const preset = ref<RangePreset>('30')
	const customFrom = ref(eatDate(-29))
	const customTo = ref(eatDate(0))

	const panels = reactive({
		overview: panel<OverviewResponse>(),
		timeseries: panel<TimeseriesResponse>(),
		funnel: panel<FunnelResponse>(),
		payments: panel<PaymentsResponse>(),
		entitlements: panel<EntitlementsResponse>(),
	})
	// Per-panel request counter: only the latest request for a panel may write its state.
	const tokens: Record<PanelKey, number> = { overview: 0, timeseries: 0, funnel: 0, payments: 0, entitlements: 0 }

	/** Client-side guard only; the server enforces from<=to and <=366 days. */
	const customError = computed<string | null>(() => {
		if (preset.value !== 'custom') {
			return null
		}
		if (!customFrom.value || !customTo.value || customFrom.value > customTo.value) {
			return 'invalid_range'
		}
		const days = (Date.parse(customTo.value) - Date.parse(customFrom.value)) / 86_400_000 + 1
		return days > MAX_DAYS ? 'range_too_large' : null
	})

	const params = computed<RangeParams>(() => {
		if (preset.value === 'custom') {
			return { from: customFrom.value, to: customTo.value, tz: TZ }
		}
		const days = Number(preset.value)
		return { from: eatDate(-(days - 1)), to: eatDate(0), tz: TZ }
	})

	async function load<T>(key: PanelKey, fetcher: (p: RangeParams) => Promise<T>, isEmpty: (d: T) => boolean) {
		const token = ++tokens[key]
		const target = panels[key] as unknown as PanelState<T>
		target.status = 'loading'
		target.error = null
		try {
			const data = await fetcher(params.value)
			// A newer request (e.g. a different range) started meanwhile; this response is stale.
			if (token !== tokens[key]) {
				return
			}
			target.data = data
			target.status = isEmpty(data) ? 'empty' : 'ready'
		} catch (err) {
			if (token !== tokens[key]) {
				return
			}
			target.data = null
			target.error = err instanceof OperationsApiError ? err.code : 'error'
			target.status = 'error'
		}
	}

	const loaders: Record<PanelKey, () => Promise<void>> = {
		// 'empty' means nothing happened in the range, judged on period activity only;
		// point-in-time account counts say nothing about the range.
		overview: () => load('overview', operationsApi.overview, (d: OverviewResponse) =>
			d.metrics.signaturesCollected.value === 0
			&& d.metrics.documentsCreated.value === 0
			&& d.metrics.signingPaymentSuccess.denominator === 0
			&& d.metrics.creditPurchaseSuccess.denominator === 0
			&& d.metrics.revenue.byCurrency.length === 0),
		timeseries: () => load('timeseries', operationsApi.timeseries, (d: TimeseriesResponse) => {
			const counts = [d.series.signatures, d.series.paymentAttempts, d.series.documentsCreated]
			return counts.every((s) => !s || s.every((p) => p.count === 0))
		}),
		funnel: () => load('funnel', operationsApi.funnel, (d: FunnelResponse) => d.stages.every((s) => s.count === 0)),
		payments: () => load('payments', operationsApi.payments, (d: PaymentsResponse) => d.providers.length === 0),
		entitlements: () => load('entitlements', operationsApi.entitlements, (d: EntitlementsResponse) =>
			d.metrics.availableCredits.byProduct.length === 0
			&& Object.keys(d.metrics.creditGrants.byType).length === 0),
	}

	function reload(key?: PanelKey) {
		// An invalid custom range is never sent: the panels keep their last state and the range control shows the error.
		if (customError.value) {
			return
		}
		return Promise.all((key ? [key] : (Object.keys(loaders) as PanelKey[])).map((k) => loaders[k]()))
	}

	function setPreset(next: RangePreset) {
		preset.value = next
		reload()
	}

	function applyCustom(from: string, to: string) {
		customFrom.value = from
		customTo.value = to
		preset.value = 'custom'
		reload()
	}

	return { preset, customFrom, customTo, customError, params, panels, reload, setPreset, applyCustom }
}
