export const DEFAULT_SIGN_PRODUCT_CODE = 'SIGN_DOCUMENT'
export const DEFAULT_PRODUCT_CODE = 'SIGN_DOCUMENT'

/**
 * Product code for the one-time personal digital certificate purchase.
 *
 * Priced/administered like any other product (admin ProductManagement),
 * but at payment finalise it grants time-bound certificate access via
 * the `certificate_purchase` purpose rather than a use-based entitlement.
 */
export const CERTIFICATE_PRODUCT_CODE = 'CERTIFICATE_ACCESS'
