/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export const DEFAULT_SIGN_PRODUCT_CODE = 'SIGN_DOCUMENT'
export const DEFAULT_PRODUCT_CODE = 'SIGN_DOCUMENT'

/**
 * Product codes tracked by the entitlement coordinator and event bus.
 *
 * Certificate access remains tracked even though its purchase flow is
 * handled externally, because entitlement state still needs to be refreshed.
 */
export type ProductCode = 'SIGN_DOCUMENT' | 'CERTIFICATE_ACCESS'

/**
 * Product code for the one-time personal digital certificate purchase.
 *
 * Priced/administered like any other product (admin ProductManagement),
 * but at payment finalise it grants time-bound certificate access via
 * the `certificate_purchase` purpose rather than a use-based entitlement.
 */
export const CERTIFICATE_PRODUCT_CODE = 'CERTIFICATE_ACCESS'
