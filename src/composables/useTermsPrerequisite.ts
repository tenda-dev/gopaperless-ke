/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * External prerequisite state of the Terms of Service app.
 *
 * - `accepted`     : enabled and the user has signed the applicable terms (or none apply)
 * - `not-enabled`  : the ToS app is not enabled for this user
 * - `pending`      : enabled and the user has not signed
 * - `unknown`      : acceptance could not be established; automatic dialogs remain suppressed
 */
export type ToSPrereq = 'accepted' | 'pending' | 'not-enabled' | 'unknown'

/**
 * Resolves the ToS prerequisite for automatic dialog presentation.
 *
 * App availability comes from server-provided initial state; acceptance is
 * determined by the ToS app's response. Resolution failures remain `unknown`
 * rather than allowing automatic dialogs to present without confirmation.
 */
export function useTermsPrerequisite(): { resolve: () => Promise<ToSPrereq> } {
	async function resolve(): Promise<ToSPrereq> {
		const enabled = loadState<boolean>('libresign', 'tos_app_enabled', false)
		if (!enabled) {
			// Retained diagnostic for the server-side ToS state used by the dialog gate.
			console.debug('[gp-dialogs] ToS prereq → not-enabled (app not enabled for this user)')
			return 'not-enabled'
		}
		try {
			const response = await axios.get(generateOcsUrl('/apps/terms_of_service/terms'))
			const data = response?.data?.ocs?.data
			console.debug('[gp-dialogs] ToS /terms', { termsCount: Array.isArray(data?.terms) ? data.terms.length : '(not array)', hasSigned: data?.hasSigned })
			if (Array.isArray(data?.terms) && data.terms.length === 0) {
				return 'accepted'
			}
			if (typeof data?.hasSigned !== 'boolean') {
				return 'unknown'
			}
			return data.hasSigned ? 'accepted' : 'pending'
		} catch {
			return 'unknown'
		}
	}

	return { resolve }
}
