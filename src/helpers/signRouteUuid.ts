/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'

// Anchor on the app segment so a UUID elsewhere in the path is never picked
// up. Allow any prefix for webroot and /index.php. Keep this in sync with the
// signing routes in src/router/router.ts: the public link and its client-side
// redirect target (/pdf), the sign-in and renew-email children, and the
// internal /pdf view.
const SIGN_ROUTE = new RegExp(
	`/apps/libresign/(?:p/sign/(${UUID})(?:/(?:pdf|sign-in|renew/email))?|f/sign/(${UUID})/pdf)/?$`,
	'i',
)

/**
 * The sign request uuid of the signing page this pathname belongs to, or undefined.
 *
 * The canonical email link is /p/sign/<uuid>, but the router replaces it with
 * /p/sign/<uuid>/pdf before anything renders, so the uuid cannot be assumed to
 * end the path.
 */
export function signUuidFromPath(pathname: string): string | undefined {
	const match = SIGN_ROUTE.exec(pathname)
	return match?.[1] ?? match?.[2]
}
