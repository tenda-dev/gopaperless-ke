/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Response contracts of /api/v1/operations */

export interface Meta {
	from: string
	to: string
	tz: string
	inclusive: 'both'
	generatedAt: string
}

/**
 * The server could not provide the metric (e.g. no source data). It is distinct
 * from a measured 0 and must be rendered as unavailable, never coerced to 0.
 */
export interface Unavailable {
	available: false
	reason: string
}

export interface ValueMetric {
	value: number
	grain: 'period' | 'point_in_time'
	/** false = an estimate (shown with the "estimate" provenance marker), not a figure calculable directly from records. */
	confirmed: boolean
	/** Present and true when the number is an upper bound, not an exact count. */
	upperBound?: boolean
}

export interface RateMetric {
	grain: 'period'
	/**
	 * What is being counted. The three success rates use different units and are
	 * never blended. 'workflow' is a distinct transaction_id, which is a sign_request
	 * (one signer), not a document.
	 */
	unit: 'payment_attempt' | 'workflow'
	numerator: number
	denominator: number
	/** null when nothing reached a terminal status: no data, not a 0% rate. */
	rate: number | null
}

export interface RevenueMetric {
	grain: 'period'
	timeField: 'paid_at'
	byCurrency: Array<{ currency: string, amountMinor: number }>
}

export type MaybeValue = ValueMetric | Unavailable

/** Rates are bucketed by the attempt's created_at; revenue alone is bucketed by paid_at. */
export interface PaymentMetrics {
	signingPaymentSuccess: RateMetric
	signingWorkflowSuccess: RateMetric
	creditPurchaseSuccess: RateMetric
	revenue: RevenueMetric
}

export interface OverviewResponse {
	meta: Meta
	metrics: PaymentMetrics & {
		registeredAccountsLifetime: MaybeValue
		enabledAccounts: MaybeValue
		newRegistrations: Unavailable
		usersWhoSigned: ValueMetric
		/** Upper bound (see upperBound): a person signing by account and by email is counted twice. */
		distinctSignersInclEmail: ValueMetric
		signaturesCollected: ValueMetric
		documentsCreated: ValueMetric
		completedDocuments: ValueMetric
		/** The same figure the entitlements endpoint serves, so one window never shows two values. */
		creditConsumption: CreditConsumptionMetric | Unavailable
	}
}

export interface DailyPoint {
	date: string
	count: number
}

export interface TimeseriesResponse {
	meta: Meta
	series: {
		signatures?: DailyPoint[]
		documentsCreated?: DailyPoint[]
		completedDocuments?: DailyPoint[]
		paymentAttempts?: DailyPoint[]
		/** Paid attempts, bucketed by when the attempt was created (not when it was confirmed). */
		paymentsPaid?: DailyPoint[]
		/** Always unavailable: core accounts have no creation timestamp to bucket by. */
		newRegistrations: Unavailable
	}
}

export interface FunnelResponse {
	meta: Meta
	/** True when stages have different units/time-anchors: render no conversion rates. */
	unitsDiffer: boolean
	stages: Array<{
		key: 'workflowsInitiated' | 'signaturesCompleted' | 'fullyCompletedDocuments'
		unit: 'documents' | 'signatures'
		/** Date anchor of this stage (created_at, signed or completion); it differs between stages. */
		bucket: string
		count: number
	}>
	publicUpload: { byStatus: Record<string, number> }
}

export interface PaymentsResponse {
	meta: Meta
	metrics: PaymentMetrics
	outcomes: {
		signRequest: Record<string, number>
		creditPurchase: Record<string, number>
	}
	providers: Array<{ provider: string, byStatus: Record<string, number>, attempts: number }>
}

/** Funding mix of completed documents (F1). A document counts once per category it appears in. */
export interface FundingMetric {
	grain: 'period'
	unit: 'documents'
	dateField: 'completion'
	/** True: categories can overlap, so they need not add up to completedDocuments. */
	multiBucket: boolean
	completedDocuments: number
	categories: {
		requesterSponsored: number
		selfEntitlement: number
		directPay: number
		unknown: number
	}
	/** Entitlement type (PAY_AS_YOU_GO, ...) or UNKNOWN => documents. Credit-funded documents only. */
	byEntitlementType: Record<string, number>
}

/**
 * Credits consumed in the period by entitlement type (F2). Dated by the signing
 * date, a proxy: no consumption timestamp exists. Counts settled signings only;
 * reservations and sponsorship records are not consumption. Credit-grained,
 * whereas FundingMetric.byEntitlementType is document-grained.
 */
export interface CreditConsumptionMetric {
	grain: 'period'
	unit: 'credits'
	timeField: 'signed'
	basis: 'one_credit_per_settled_signing'
	total: number
	byType: Record<string, number>
}

/** Requester sponsorships CREATED in the period (F3): intent, not consumption. */
export interface RequesterActivity {
	label: 'sponsorships_created'
	records: number
	documents: number
}

export interface EntitlementsResponse {
	meta: Meta
	metrics: {
		availableCredits: {
			grain: 'point_in_time'
			/** Credits left before reservations. Not reduced by a reservation, only by consumption at settlement. */
			remaining: number
			/** Committed to signing workflows in progress: unavailable for other use, but not consumed. */
			reserved: number
			/** remaining - reserved. */
			available: number
			/** Entitlements with no usage limit; they are excluded from the sums above. */
			unlimitedEntitlements: number
			byProduct: Array<{ productCode: string, remaining: number, reserved: number, unlimited: number }>
		}
		creditGrants: { grain: 'period', byType: Record<string, number> }
		/** Sponsorship records created in the period (intent), not completed signings or consumed credits. */
		sponsorshipMix: { grain: 'period', byType: Record<string, number>, requesterActivity?: RequesterActivity }
		creditConsumption: CreditConsumptionMetric | Unavailable
		/** Optional: servers that predate the funding block omit it, so consumers must tolerate its absence. */
		funding?: FundingMetric | Unavailable
	}
}

export type RangePreset = '7' | '30' | '90' | 'custom'

export interface RangeParams {
	from: string
	to: string
	tz: string
}

export type PanelStatus = 'loading' | 'ready' | 'empty' | 'error'

export interface PanelState<T> {
	status: PanelStatus
	data: T | null
	/** Stable server code (invalid_range, range_too_large, forbidden, ...) or 'network'. */
	error: string | null
}

/** Narrowing guard, so every consumer branches on availability instead of reading `.value` as 0. */
export function isUnavailable(metric: MaybeValue | Unavailable): metric is Unavailable {
	return (metric as Unavailable).available === false
}
