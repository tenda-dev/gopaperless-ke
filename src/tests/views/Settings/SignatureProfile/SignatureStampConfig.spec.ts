/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount, type DOMWrapper } from '@vue/test-utils'

import SignatureStampConfig from '../../../../views/Settings/SignatureProfile/SignatureStampConfig.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, string: string) => string),
}))

vi.mock('../../../../views/Settings/SignatureProfile/DimensionInputs.vue', () => ({
	default: {
		name: 'DimensionInputs',
		props: ['modelValue', 'globals'],
		template: '<div class="dimension-inputs-stub" />',
	},
}))

describe('SignatureStampConfig.vue', () => {
	const globals = {
		renderMode: 'GRAPHIC_AND_DESCRIPTION',
		textTemplate: 'Global template',
		signatureFontSize: 18,
		templateFontSize: 10,
		width: 350,
		height: 100,
	}

	const createWrapper = (props: Record<string, unknown> = {}) => mount(SignatureStampConfig, {
		props: {
			modelValue: {
				enabled: true,
				renderMode: '',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
			globals,
			...props,
		},
	})

	const findInputByValue = (inputs: DOMWrapper<Element>[], value: string) => {
		return inputs.find((input) => (input.element as HTMLInputElement).value === value)
	}

	it('selects inherit global render mode by default', async () => {
		const wrapper = createWrapper()
		const inputs = wrapper.findAll('input[name="stamp-render-mode"]')
		const inheritInput = findInputByValue(inputs, '')

		expect(inheritInput).toBeDefined()
		expect((inheritInput!.element as HTMLInputElement).checked).toBe(true)
	})

	it('emits an override render mode when a concrete mode is selected', async () => {
		const wrapper = createWrapper()
		const overrideInput = findInputByValue(
			wrapper.findAll('input[name="stamp-render-mode"]'),
			'DESCRIPTION_ONLY',
		)

		await overrideInput!.trigger('change')

		expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
		expect(wrapper.emitted('update:modelValue')![0]).toEqual([
			{
				enabled: true,
				renderMode: 'DESCRIPTION_ONLY',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		])
	})

	it('emits inherit global render mode when the empty option is selected', async () => {
		const wrapper = createWrapper({
			modelValue: {
				enabled: true,
				renderMode: 'DESCRIPTION_ONLY',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		})

		const inheritInput = findInputByValue(
			wrapper.findAll('input[name="stamp-render-mode"]'),
			'',
		)

		await inheritInput!.trigger('change')

		expect(wrapper.emitted('update:modelValue')![0]).toEqual([
			{
				enabled: true,
				renderMode: '',
				textTemplate: '',
				signatureFontSize: '',
				templateFontSize: '',
				width: '',
				height: '',
			},
		])
	})

	it('emits a model update when the text template changes', async () => {
		const wrapper = createWrapper()
		const textarea = wrapper.find('textarea')

		await textarea.setValue('Custom template')

		expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
		expect(wrapper.emitted('update:modelValue')![0][0]).toMatchObject({
			enabled: true,
			textTemplate: 'Custom template',
		})
	})
})
