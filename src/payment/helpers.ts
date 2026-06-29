/**
<<<<<<< Updated upstream
 * Extract payment-related query params from the current URL.
 *
 * DPO preserves any custom query parameters supplied on RedirectURL,
 * allowing us to recover the original destination after verification.
=======
 * Extract payment-related query params from URL
 *
 * DPO callback param casing is inconsistent across providers/tests,
 * so we lookup keys case-insensitively.
>>>>>>> Stashed changes
 */
export function getPaymentFromUrl() {
	const params = new URLSearchParams(window.location.search)

<<<<<<< Updated upstream
	return {
		transactionToken: params.get('TransactionToken'),
		companyRef: params.get('CompanyRef'),
		transId: params.get('TransID'),
		ccdApproval: params.get('CCDapproval'),
		pnrId: params.get('PnrID'),
		returnTo: params.get('returnTo'),
=======
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
	const pnrId = getParamIgnoreCase('PnrID')

	return {
		transactionToken,
		signUuid: signUuid || companyRef || pnrId || null,
		companyRef,
		status: getParamIgnoreCase('status'),
>>>>>>> Stashed changes
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

	window.history.replaceState({}, '', url.toString())
}
