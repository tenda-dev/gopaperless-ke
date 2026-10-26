/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Resolves mobile network operator (MNO) identifiers to their display logos.
 *
 * Provider identifiers may come from the backend or frontend detection logic
 * and can differ in spelling, casing, or format. `canonicalMno()` normalizes
 * these values to a shared provider key, including supported country suffixes
 * and STK/version markers.
 *
 * Logo resolution follows this precedence:
 * 1. A bundled logo mapped to the canonical provider key.
 * 2. A logo URL supplied by the backend for providers without a bundled asset.
 * 3. `null`, allowing the caller to render a fallback such as a monogram.
 *
 * The asset map is intentionally keyed by provider rather than country because
 * a provider's branding can be reused across supported regions.
 *
 * To bundle a local logo, import the asset and register it in `MNO_ASSETS`.
 */

import airtelLogo from '../../img/payment/mno/airtel_africa.webp'
import mpesaLogo from '../../img/payment/mno/mpesa.webp'

const MNO_ASSETS: Record<string, string> = {
	mpesa: mpesaLogo,
	airtel: airtelLogo,
}

/**
 * Maps known provider identifiers and aliases to canonical provider keys.
 * `canonicalMno()` also handles supported country suffixes and STK/version
 * markers, avoiding separate aliases for every backend-specific variant.
 */
const ALIASES: Record<string, string> = {
	mpesa: 'mpesa', safaricom: 'mpesa', vodacom: 'vodacom',
	airtel: 'airtel', airtelmoney: 'airtel',
	mtn: 'mtn', tigo: 'tigo', tigopesa: 'tigo', tnm: 'tnm', tnmmpamba: 'tnm',
	ecocash: 'ecocash', onemoney: 'onemoney', onewallet: 'onemoney', telecash: 'telecash',
	zamtel: 'zamtel', halotel: 'halotel', zantel: 'zantel', ttcl: 'ttcl', africell: 'africell', faiba: 'faiba',
}

/**
 * Country codes recognised as trailing provider-key suffixes.
 * Only these suffixes are stripped during canonicalization.
 */
const REGION_SUFFIX = /(ke|tz|ug|rw|mw|zm|zw)$/

/**
 * Normalises a provider identifier into a canonical key.
 *
 * Handles differences in casing, separators, known aliases, STK/version
 * markers, and supported trailing country codes. Unknown identifiers are
 * returned in normalised form so they can still be used for backend-logo
 * lookup.
 */
export function canonicalMno(provider?: string | null): string {
	let key = (provider ?? '').trim().toLowerCase().replace(/[\s._-]+/g, '')

	if (ALIASES[key]) return ALIASES[key]

	key = key.replace(/stkv?\d*$/, '').replace(/v\d+$/, '')

	if (ALIASES[key]) return ALIASES[key]

	const stripped = key.replace(REGION_SUFFIX, '')
	return ALIASES[stripped] ?? stripped ?? key
}

export function logoFor(provider?: string | null, backendLogo?: string | null): string | null {
	return MNO_ASSETS[canonicalMno(provider)] ?? backendLogo ?? null
}
