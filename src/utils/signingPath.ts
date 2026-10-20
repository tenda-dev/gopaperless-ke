/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A signing link's path, and nothing else. The login page sends someone back
 * to it after Switch account, so anything wider would be an open redirect.
 */
export const isSigningPath = (path: string): boolean =>
	/^(?:\/index\.php)?\/apps\/libresign\/p\/sign\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(path)
