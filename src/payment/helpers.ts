/**
 * Extract payment-related query params from the current URL.
 *
 * DPO callback param casing is inconsistent across providers/tests,
 * so we lookup keys case-insensitively. DPO preserves any custom query
 * parameters supplied on RedirectURL, allowing us to recover the original
 * destination after verification.
 */
export function getPaymentFromUrl() {
	const params = new URLSearchParams(window.location.search)

	const getParamIgnoreCase = (key: string): string | null => {
		for (const [k, v] of params.entries()) {
			if (k.toLowerCase() === key.toLowerCase()) {
				return v
			}
		}
		return null
	}

	const transactionToken = getParamIgnoreCase('TransactionToken')
	const signUuid = getParamIgnoreCase('signUuid')
	const companyRef = getParamIgnoreCase('CompanyRef')
	const transId = getParamIgnoreCase('TransID')
	const ccdApproval = getParamIgnoreCase('CCDapproval')
	const pnrId = getParamIgnoreCase('PnrID')
	const returnTo = getParamIgnoreCase('returnTo')
	const status = getParamIgnoreCase('status')

	return {
		transactionToken,
		signUuid: signUuid || companyRef || pnrId || null,
		companyRef,
		transId,
		ccdApproval,
		pnrId,
		returnTo,
		status,
	}
}

/**
 * Remove payment-related query params from the current URL
 * after verification completes.
 */
export function clearPaymentParamsFromUrl() {
	const url = new URL(window.location.href)

	url.searchParams.delete('TransactionToken')
	url.searchParams.delete('CompanyRef')
	url.searchParams.delete('TransID')
	url.searchParams.delete('CCDapproval')
	url.searchParams.delete('PnrID')
	url.searchParams.delete('returnTo')

	window.history.replaceState({}, document.title, url.toString())
}
