/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { vi, afterEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { cleanup } from '@testing-library/vue'
import { createL10nMock } from './testHelpers/l10n.js'

setActivePinia(createPinia())

// Global error handlers: throw on console warnings and errors for early detection
const originalError = console.error
const originalWarn = console.warn

console.error = function (...args) {
	const message = args[0]
	// Allow legitimate error logging, but throw for assertion failures
	if (message instanceof Error || typeof message === 'string') {
		throw new Error(String(message))
	}
	return originalError.apply(console, args)
}

console.warn = function (...args) {
	// Log warnings without throwing so that missing-mock warnings do not mask
	// the actual test assertions. Tests should still assert behavior.
	return originalWarn.apply(console, args)
}

vi.mock('@vue/test-utils', async (importOriginal) => {
	const actual = await importOriginal()

	return {
		...actual,
		mount: actual.mount,
		shallowMount: actual.shallowMount,
	}
})

vi.mock('vue-select', () => ({
	default: {
		name: 'VueSelect',
		render() {
			return null
		},
	},
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		template: '<div></div>',
	},
}))


vi.mock('@nextcloud/vue/components/NcRichText', () => ({
	default: {
		name: 'NcRichText',
		template: '<div></div>',
	},
}))

vi.mock('@nextcloud/vue', () => ({
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		template: '<div />',
	},
}))

globalThis.mockNextcloudL10n = (options) => createL10nMock(options)

// Automatically cleanup after each test
afterEach(() => {
	cleanup()
})

setActivePinia(createPinia())

// Prevent the user context store from issuing real network requests during tests.
import { useUserContextStore } from '@/store/userContext.ts'
useUserContextStore().$patch({
	initialised: true,
	uid: 'test-user',
	emailAddress: 'test@example.com',
	displayName: 'Test User',
	phoneNumber: '',
})

// Provide a fallback account/me response for components that create a fresh Pinia.
import axios from '@nextcloud/axios'
axios.get = vi.fn().mockResolvedValue({
	data: {
		ocs: {
			data: {
				account: {
					uid: 'test-user',
					emailAddress: 'test@example.com',
					displayName: 'Test User',
				},
				settings: {
					phoneNumber: '',
				},
				// Default test user is grandfathered/valid so `certRequired`
				// stays false and existing component tests don't trip the gate.
				extended: {
					createdAt: '2026-01-01T00:00:00+00:00',
					paidCertificate: false,
					certPaidAt: null,
					validUntil: null,
					isCertificateValid: true,
				},
			},
		},
	},
})

import './testHelpers/jsdomMocks.js'
import './testHelpers/nextcloudMocks.js'
import './testHelpers/vueMocks.js'

