/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createL10nMock, interpolateL10n } from '../../testHelpers/l10n.js'
import { mount } from '@vue/test-utils'
import type { SignerSummaryRecord } from '../../../types/index'

import SignatureBox from '../../../components/PdfEditor/SignatureBox.vue'

const createSigner = (overrides: Partial<SignerSummaryRecord> = {}): SignerSummaryRecord => ({
	signRequestId: 0,
	displayName: '',
	email: '',
	signed: null,
	status: 0,
	statusText: '',
	...overrides,
})

const usernameToColorMock = vi.fn((_seed: string) => ({ r: 10, g: 20, b: 30 }))

vi.mock('@nextcloud/l10n', () => createL10nMock({
	t: (_app: string, text: string, vars?: Record<string, string | number>) => interpolateL10n(text, vars),
	n: (_app: string, singular: string, plural: string, count: number, vars?: Record<string, string | number>) => {
		const template = count === 1 ? singular : plural
		return interpolateL10n(template, { count, ...(vars ?? {}) })
	},
	translate: (_app: string, text: string, vars?: Record<string, string | number>) => interpolateL10n(text, vars),
	translatePlural: (_app: string, singular: string, plural: string, count: number, vars?: Record<string, string | number>) => {
		const template = count === 1 ? singular : plural
		return interpolateL10n(template, { count, ...(vars ?? {}) })
	},
}))

vi.mock('@nextcloud/vue/functions/usernameToColor', () => ({
	usernameToColor: (seed: string) => usernameToColorMock(seed),
}))

const signingCardMock = vi.fn(() => Promise.resolve({
	name: 'Jane Wanjiku Njoroge',
	issuer: 'Signa Hardware CA',
	handwriting: 'data:image/png;base64,AAAA',
}))
vi.mock('../../../services/signingCard', () => ({
	signingCard: () => signingCardMock(),
}))

describe('SignatureBox.vue', () => {
	it('computes the aria label from the label prop', () => {
		const wrapper = mount(SignatureBox, {
			props: {
				label: 'Ada Lovelace',
			},
		})

		expect(wrapper.attributes('aria-label')).toBe('Signature position for Ada Lovelace')
	})

	it('uses signer displayName as the color seed when available', () => {
		const wrapper = mount(SignatureBox, {
			props: {
				label: 'Fallback',
				signer: createSigner({ displayName: 'Grace Hopper' }),
			},
		})

		expect(usernameToColorMock).toHaveBeenCalledWith('Grace Hopper')
		// No border around the signature: the position is a light tint.
		expect(wrapper.vm.boxStyle).toEqual({
			backgroundColor: 'rgba(10, 20, 30, 0.12)',
		})
	})

	it('returns an empty style object when there is no seed', () => {
		const wrapper = mount(SignatureBox)

		expect(wrapper.vm.boxStyle).toEqual({})
	})

	it('previews another signer box as the signing card with placeholders', async () => {
		const wrapper = mount(SignatureBox, {
			props: {
				label: 'Grace Hopper',
				signer: createSigner({ displayName: 'Grace Hopper' }),
			},
		})
		await flushPromises()

		expect(signingCardMock).not.toHaveBeenCalled()
		expect(wrapper.find('.signing-card__name').text()).toBe('GRACE HOPPER')
		expect(wrapper.find('.signing-card__issuer').text()).toBe('ISSUER: CERTIFICATE ISSUER')
		expect(wrapper.find('.signing-card__time').text()).toBe('Date and time of signing (EAT)')
		expect(wrapper.find('img').exists()).toBe(false)
	})

	it('previews the signed-in user box with their handwriting, verified name and issuer', async () => {
		const wrapper = mount(SignatureBox, {
			props: {
				label: 'Jane',
				signer: { ...createSigner({ displayName: 'Jane' }), me: true } as SignerSummaryRecord,
			},
		})
		await flushPromises()

		expect(wrapper.find('.signing-card__name').text()).toBe('JANE WANJIKU NJOROGE')
		expect(wrapper.find('.signing-card__issuer').text()).toBe('ISSUER: SIGNA HARDWARE CA')
		expect(wrapper.find('img').attributes('src')).toBe('data:image/png;base64,AAAA')
	})
})
