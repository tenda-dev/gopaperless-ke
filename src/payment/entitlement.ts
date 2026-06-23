import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const BASE_URL = '/apps/libresign/api/v1/entitlement'
const CONSUME_ENDPOINT = `${BASE_URL}/xzy-mspw-cbs`

export interface CurrentEntitlement {
	id: number
	userId: string
	productCode: string
	remainingUses: number
}

export interface ConsumeEntitlementPayload {
	userId: string
	signUuid: string
	signRequestId: number
	productCode: string
}

export interface EntitlementAuthorisation {
	allowed: boolean
}

/**
 * Calls the entitlement consumption endpoint after a successful signing.
 * This is a non-blocking call (does not throw) since we don't want to impact the signing flow in case of issues with entitlement service.
 *
 * @param payload - object containing necessary info for entitlement consumption
 *   - signUuid: string (required) - UUID of the signer (from backend)
 *   - signRequestId: number (required) - ID of the sign request (from backend)
 *   - productCode: string (required) - code of the product being consumed
 *   - userId: string (optional) - user ID, if not provided it will be fetched from getUser()
 */

export async function consumeEntitlement(
	payload: ConsumeEntitlementPayload,
): Promise<boolean> {

	try {

		const { data } = await axios.post(
			generateOcsUrl(CONSUME_ENDPOINT),
			payload,
			{
				timeout: 10000,
			},
		)

		const result = data?.ocs?.data

		if (!result?.success) {

			console.error(
				'[Entitlement] Consumption failed',
				result?.message,
			)

			return false
		}

		return true

	} catch (err) {

		console.error(
			'[Entitlement] Consumption failed',
			err,
		)

		return false
	}
}

export async function getCurrentEntitlement(
	productCode: string,
): Promise<CurrentEntitlement | null> {

	const { data } = await axios.get(
		generateOcsUrl(
			`${BASE_URL}/current?productCode=${productCode}`
		),
		{
			timeout: 10000,
		},
	)

	return data?.ocs?.data?.entitlement ?? null
}

export async function checkEntitlement(
	productCode: string,
): Promise<EntitlementAuthorisation> {

	const { data } = await axios.get(
		generateOcsUrl(
			`${BASE_URL}/check?productCode=${productCode}`,
		),
		{
			timeout: 10000,
		},
	)

	return data?.ocs?.data ?? {
		allowed: false,
	}
}
