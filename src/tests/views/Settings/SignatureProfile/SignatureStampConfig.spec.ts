/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

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

	it('selects inherit global render mode by default', async () => {
		const wrapper = createWrapper()
		const inputs = wrapper.findAll('input[name="stamp-render-mode"]')
		const inheritInput = inputs.find((input) => input.element.value === '')

		expect(inheritInput).toBeDefined()
		expect(inheritInput!.element.checked).toBe(true)
	})

	it('emits an override render mode when a concrete mode is selected', async () => {
		const wrapper = createWrapper()
		const overrideInput = wrapper.findAll('input[name="stamp-render-mode"]')
			.find((input) => input.element.value === 'DESCRIPTION_ONLY')

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

		const inheritInput = wrapper.findAll('input[name="stamp-render-mode"]')
			.find((input) => input.element.value === '')

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

	it('toggles the enabled flag through the emitted model update', async () => {
		const wrapper = createWrapper()

		wrapper.vm.update('enabled', false)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
		expect(wrapper.emitted('update:modelValue')![0][0]).toMatchObject({ enabled: false })
	})
})
