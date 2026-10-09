/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** What SecurySign puts on the signed-in user's signing card; the time is set at signing. */
export type SigningCard = {
	name: string
	issuer: string
	handwriting: string
}

let card: Promise<SigningCard | null> | null = null

/** Fetched once per page: every signature box of the user's shows the same card. */
export function signingCard(): Promise<SigningCard | null> {
	card ??= axios.get(generateUrl('/apps/libresign/securysign/card'))
		.then(({ data }) => (data?.card ?? null) as SigningCard | null)
		.catch(() => null)
	return card
}
