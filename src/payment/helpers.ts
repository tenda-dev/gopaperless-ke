/**
 * Extract payment-related query params from the current URL.
 *
 * DPO preserves any custom query parameters supplied on RedirectURL,
 * allowing us to recover the original destination after verification.
 */
export function getPaymentFromUrl() {
	const params = new URLSearchParams(window.location.search)

	return {
		transactionToken: params.get('TransactionToken'),
		companyRef: params.get('CompanyRef'),
		transId: params.get('TransID'),
		ccdApproval: params.get('CCDapproval'),
		pnrId: params.get('PnrID'),
		returnTo: params.get('returnTo'),
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
