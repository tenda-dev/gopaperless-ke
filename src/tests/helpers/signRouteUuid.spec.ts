/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { signUuidFromPath } from '../../helpers/signRouteUuid'

const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'
const OTHER = '11111111-2222-4333-8444-555555555555'

describe('signUuidFromPath', () => {
	it.each([
		['the canonical email link', `/apps/libresign/p/sign/${UUID}`],
		['its client-side redirect target', `/apps/libresign/p/sign/${UUID}/pdf`],
		['the sign-in child', `/apps/libresign/p/sign/${UUID}/sign-in`],
		['the renew-email child', `/apps/libresign/p/sign/${UUID}/renew/email`],
		['the internal pdf view', `/apps/libresign/f/sign/${UUID}/pdf`],
		['an index.php install', `/index.php/apps/libresign/p/sign/${UUID}/pdf`],
		['a subfolder install', `/nextcloud/index.php/apps/libresign/p/sign/${UUID}`],
		['a trailing slash', `/apps/libresign/p/sign/${UUID}/pdf/`],
		['an upper-case uuid', `/apps/libresign/p/sign/${UUID.toUpperCase()}/pdf`],
	])('extracts the uuid from %s', (_label, path) => {
		expect(signUuidFromPath(path)?.toLowerCase()).toBe(UUID)
	})

	it.each([
		['a malformed uuid', '/apps/libresign/p/sign/not-a-uuid/pdf'],
		['an unknown child segment', `/apps/libresign/p/sign/${UUID}/unrelated`],
		['an unrelated page', '/apps/libresign/f/request'],
		['the internal route without /pdf', `/apps/libresign/f/sign/${UUID}`],
		['the internal route with another child', `/apps/libresign/f/sign/${UUID}/sign-in`],
		['a uuid in an unrelated path', `/apps/libresign/f/validation/${UUID}`],
		['a uuid outside the app segment', `/other/p/sign/${UUID}/pdf`],
		['a second uuid after the first', `/apps/libresign/p/sign/${UUID}/${OTHER}`],
		['a uuid in a query-looking tail', `/apps/libresign/f/request/p/sign/${UUID}x`],
		['an empty path', ''],
	])('rejects %s', (_label, path) => {
		expect(signUuidFromPath(path)).toBeUndefined()
	})
})
