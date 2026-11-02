/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en' }))

const api = vi.hoisted(() => ({
	overview: vi.fn(),
	timeseries: vi.fn(),
	funnel: vi.fn(),
	payments: vi.fn(),
	entitlements: vi.fn(),
}))
vi.mock('../../../views/Operations/api.ts', async () => {
	class OperationsApiError extends Error {
		constructor(public readonly code: string, public readonly status: number | null) {
			super(code)
		}
	}
	return { operationsApi: api, OperationsApiError }
})

import { OperationsApiError } from '../../../views/Operations/api.ts'
import { useOperationsData } from '../../../views/Operations/composables/useOperationsData.ts'

const zeroOverview = {
	metrics: {
		signaturesCollected: { value: 0 },
		documentsCreated: { value: 0 },
		signingPaymentSuccess: { denominator: 0 },
		creditPurchaseSuccess: { denominator: 0 },
		revenue: { byCurrency: [] },
	},
}

function resolveAll() {
	api.overview.mockResolvedValue({ metrics: { ...zeroOverview.metrics, signaturesCollected: { value: 3 } } })
	api.timeseries.mockResolvedValue({ series: { signatures: [{ date: '2026-10-28', count: 1 }] } })
	api.funnel.mockResolvedValue({ stages: [{ key: 'created', count: 2 }] })
	api.payments.mockResolvedValue({ providers: [{ provider: 'dpo' }] })
	api.entitlements.mockResolvedValue({ metrics: { availableCredits: { byProduct: [{}] }, creditGrants: { byType: {} } } })
}

describe('useOperationsData', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
	})

	it('loads every panel independently and marks them ready', async () => {
		resolveAll()
		const { panels, reload } = useOperationsData()

		await reload()

		expect(Object.values(panels).map((p) => p.status)).toEqual(['ready', 'ready', 'ready', 'ready', 'ready'])
	})

	it('one failing endpoint only errors its own panel, keeping the server code', async () => {
		resolveAll()
		api.payments.mockRejectedValue(new OperationsApiError('Forbidden', 403))
		const { panels, reload } = useOperationsData()

		await reload()

		expect(panels.payments.status).toBe('error')
		expect(panels.payments.error).toBe('Forbidden')
		expect(panels.overview.status).toBe('ready')
	})

	it('reports empty (not zeros) when the period has no activity', async () => {
		resolveAll()
		api.overview.mockResolvedValue(zeroOverview)
		api.funnel.mockResolvedValue({ stages: [{ key: 'created', count: 0 }] })
		const { panels, reload } = useOperationsData()

		await reload()

		expect(panels.overview.status).toBe('empty')
		expect(panels.funnel.status).toBe('empty')
	})

	it('a slow response for a previous range cannot overwrite the current one', async () => {
		resolveAll()
		let releaseStale!: (v: unknown) => void
		api.funnel.mockImplementationOnce(() => new Promise((r) => { releaseStale = r }))
		const { panels, reload } = useOperationsData()

		const first = reload('funnel')
		api.funnel.mockResolvedValueOnce({ stages: [{ key: 'created', count: 9 }] })
		await reload('funnel')
		releaseStale({ stages: [{ key: 'created', count: 1 }] })
		await first

		expect((panels.funnel.data as { stages: Array<{ count: number }> }).stages[0].count).toBe(9)
	})

	it('sends the selected preset as inclusive EAT dates', async () => {
		resolveAll()
		const { reload, setPreset, params } = useOperationsData()
		await reload()

		setPreset('7')

		const { from, to } = params.value
		const days = (Date.parse(to) - Date.parse(from)) / 86_400_000 + 1
		expect(days).toBe(7)
		expect(api.overview).toHaveBeenLastCalledWith(expect.objectContaining({ from, to, tz: 'Africa/Nairobi' }))
	})

	it('does not call the API for an invalid custom range', async () => {
		resolveAll()
		const { applyCustom, customError } = useOperationsData()

		applyCustom('2026-10-29', '2026-10-01')
		expect(customError.value).toBe('invalid_range')
		applyCustom('2025-01-01', '2026-10-29')
		expect(customError.value).toBe('range_too_large')

		expect(api.overview).not.toHaveBeenCalled()
	})
})
