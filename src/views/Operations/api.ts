/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type {
	EntitlementsResponse,
	FunnelResponse,
	OverviewResponse,
	PaymentsResponse,
	RangeParams,
	TimeseriesResponse,
} from './types.ts'

const BASE = '/apps/libresign/api/v1/operations'

/** Error carrying the server's stable `error` code (or 'network' with no response). */
export class OperationsApiError extends Error {
	constructor(public readonly code: string, public readonly status: number | null) {
		super(code)
	}
}

async function get<T>(path: string, params: RangeParams & { metrics?: string }): Promise<T> {
	try {
		// OCS envelope: the payload (or the controller's `error` code) sits under ocs.data.
		const { data } = await axios.get(generateOcsUrl(`${BASE}/${path}`), { params })
		return data?.ocs?.data as T
	} catch (err) {
		const e = err as { response?: { status?: number, data?: { ocs?: { data?: { error?: string } } } } }
		if (!e.response) {
			throw new OperationsApiError('network', null)
		}
		throw new OperationsApiError(e.response.data?.ocs?.data?.error ?? 'error', e.response.status ?? null)
	}
}

/**
 * Thin client for the operations endpoints. Every call is read-only; the
 * server authorizes each request regardless of what the UI shows.
 */
export const operationsApi = {
	overview: (p: RangeParams) => get<OverviewResponse>('overview', p),
	// Registrations are deliberately not requested: the server allow-list excludes them (no timestamp to bucket by).
	timeseries: (p: RangeParams) => get<TimeseriesResponse>('timeseries', { ...p, metrics: 'signing,payments' }),
	funnel: (p: RangeParams) => get<FunnelResponse>('funnel', p),
	payments: (p: RangeParams) => get<PaymentsResponse>('payments', p),
	entitlements: (p: RangeParams) => get<EntitlementsResponse>('entitlements', p),
}
