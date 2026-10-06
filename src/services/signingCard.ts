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

export type SigningCardLayout = 'stacked' | 'horizontal'

export type SigningCardPreview = {
	/** The signed-in user's own card, or null when SecurySign has none. */
	card: SigningCard | null
	/** How every signature box is drawn (an A/B test). */
	layout: SigningCardLayout
}

let preview: Promise<SigningCardPreview> | null = null

/** Fetched once per page: every signature box shares it. */
export function signingCard(): Promise<SigningCardPreview> {
	preview ??= axios.get(generateUrl('/apps/libresign/securysign/card'))
		.then(({ data }) => ({
			card: (data?.card ?? null) as SigningCard | null,
			layout: (data?.layout === 'horizontal' ? 'horizontal' : 'stacked') as SigningCardLayout,
		}))
		.catch(() => ({ card: null, layout: 'stacked' as const }))
	return preview
}
