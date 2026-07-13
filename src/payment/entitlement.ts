import type { SponsorshipType } from '@/types'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const BASE_URL = '/apps/libresign/api/v1/entitlement'
const CONSUME_ENDPOINT = `${BASE_URL}/xzy-mspw-cbs`

export interface AvailableCredits {
	productCode: string
	remainingUses: number
	activeEntitlements: number
	canUse: boolean
}

export interface ConsumeEntitlementPayload {
	userId: string
	signUuid: string
	signRequestId: number
	productCode: string
}

export interface SigningCoverage {
	allowed: boolean
	sponsored: boolean
	sponsorUserId: string | null
}

export interface SigningSponsorship {
	type: SponsorshipType
	sponsored: boolean
	sponsorUserId: string | null
}

export interface EntitlementAuthorisation {
	allowed: boolean
}

interface ConsumeEntitlementResponse {
	success: boolean
	remainingUses: number
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
): Promise<ConsumeEntitlementResponse> {

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

			return result
		}

		return result

	} catch (err) {

		console.error(
			'[Entitlement] Consumption failed',
			err,
		)

		return { success: false, remainingUses: 0 }
	}
}

export async function getCurrentEntitlement(
	productCode: string,
): Promise<AvailableCredits | null> {

	const { data } = await axios.get(
		generateOcsUrl(
			`${BASE_URL}/credits?productCode=${productCode}`
		),
		{
			timeout: 10000,
		},
	)

	return data?.ocs?.data?.credits ?? null
}

export async function checkEntitlement(
	productCode: string,
	signRequestId: number,
): Promise<SigningCoverage> {

	const { data } = await axios.get(
		generateOcsUrl(
			`${BASE_URL}/check?productCode=${encodeURIComponent(productCode)}&signRequestId=${signRequestId}`,
		),
		{
			timeout: 10000,
		},
	)

	return (
		data?.ocs?.data ?? {
			allowed: false,
			sponsored: false,
			sponsorUserId: null,
		}
	)
}

export async function getSigningSponsorship(
	signRequestId: number,
): Promise<SigningSponsorship> {

	const { data } = await axios.get(
		generateOcsUrl(
			`${BASE_URL}/sponsorship?signRequestId=${signRequestId}`,
		),
		{
			timeout: 10000,
		},
	)

	return (
		data?.ocs?.data ?? {
			type: 'self',
			sponsored: false,
			sponsorUserId: null,
		}
	)
}
