/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import WorkflowStepper from '../../../../components/RightSidebar/Workflow/WorkflowStepper.vue'
import type { WorkflowStep } from '../../../../composables/useWorkflowState'

vi.mock('@nextcloud/vue/components/NcIconSvgWrapper', () => ({
	default: {
		name: 'NcIconSvgWrapper',
		props: ['path'],
		template: '<i class="nc-icon-svg-wrapper-stub" :data-path="path" />',
	},
}))

function steps(completedCount: number): WorkflowStep[] {
	return ['a', 'b', 'c', 'd'].map((id, i) => ({
		id,
		label: id,
		status: i < completedCount ? 'complete' : i === completedCount ? 'current' : 'locked',
		active: i === completedCount,
		completed: i < completedCount,
	})) as WorkflowStep[]
}

describe('WorkflowStepper.vue motion opt-in', () => {
	let frames: Array<() => void>
	const flushFrame = () => {
		const queued = frames
		frames = []
		queued.forEach((cb) => cb())
	}

	beforeEach(() => {
		frames = []
		vi.stubGlobal('requestAnimationFrame', (cb: () => void) => {
			frames.push(cb)
			return frames.length
		})
		vi.stubGlobal('cancelAnimationFrame', () => {
			frames = []
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('keeps the previous behaviour when motion-key is omitted (public workflow)', () => {
		const wrapper = mount(WorkflowStepper, { props: { steps: steps(1) } })
		const root = wrapper.find('.workflow-stepper')

		expect(root.classes()).not.toContain('workflow-stepper--motion')
		expect(root.classes()).not.toContain('workflow-stepper--still')
		// Vue Transition css is disabled, so the glyph swaps immediately as before.
		expect(wrapper.find('transition-stub').attributes('css')).toBe('false')
	})

	it('opts in to motion with a motion-key', () => {
		const wrapper = mount(WorkflowStepper, { props: { steps: steps(1), motionKey: 10 } })
		const root = wrapper.find('.workflow-stepper')

		expect(root.classes()).toContain('workflow-stepper--motion')
		expect(root.classes()).not.toContain('workflow-stepper--still')
		expect(wrapper.find('transition-stub').attributes('css')).toBe('true')
	})

	it('is still (no circle/connector/glyph transitions) in the render that follows a key change', async () => {
		const wrapper = mount(WorkflowStepper, { props: { steps: steps(1), motionKey: 10 } })

		await wrapper.setProps({ motionKey: 11, steps: steps(3) })

		const root = wrapper.find('.workflow-stepper')
		expect(root.classes()).toContain('workflow-stepper--still')
		expect(wrapper.find('transition-stub').attributes('css')).toBe('false')

		flushFrame()
		flushFrame()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.workflow-stepper').classes()).not.toContain('workflow-stepper--still')
		expect(wrapper.find('transition-stub').attributes('css')).toBe('true')
	})

	it('animates a step change for the same key (not still)', async () => {
		const wrapper = mount(WorkflowStepper, { props: { steps: steps(1), motionKey: 10 } })

		await wrapper.setProps({ steps: steps(2) })

		expect(wrapper.find('.workflow-stepper').classes()).not.toContain('workflow-stepper--still')
		expect(wrapper.find('transition-stub').attributes('css')).toBe('true')
		expect(wrapper.findAll('.workflow-step-connector--active').length).toBeGreaterThan(0)
	})

	it('still renders the number or check glyph per step', () => {
		const wrapper = mount(WorkflowStepper, { props: { steps: steps(2), motionKey: 10 } })
		const circles = wrapper.findAll('.workflow-step-circle')

		expect(circles[0].find('.nc-icon-svg-wrapper-stub').exists()).toBe(true)
		expect(circles[1].find('.nc-icon-svg-wrapper-stub').exists()).toBe(true)
		expect(circles[2].text()).toBe('3')
		expect(circles[3].text()).toBe('4')
	})
	it('never strands a glyph when documents are switched rapidly (real Transition, no stub)', async () => {
		const wrapper = mount(WorkflowStepper, {
			props: { steps: steps(4), motionKey: 1 },
			global: { stubs: { transition: false } },
		})
		const settle = () => new Promise((resolve) => setTimeout(resolve, 40))

		// Alternate between an all-complete and a 2-complete document in quick succession,
		// the way clicking through the documents list does.
		for (let i = 0; i < 10; i++) {
			await wrapper.setProps({ motionKey: i + 2, steps: steps(i % 2 === 0 ? 2 : 4) })
			flushFrame()
		}
		await wrapper.setProps({ motionKey: 99, steps: steps(2) })
		flushFrame()
		flushFrame()
		await settle()
		await settle()

		const circles = wrapper.findAll('.workflow-step-circle')
		expect(circles[0].find('.nc-icon-svg-wrapper-stub').exists()).toBe(true)
		expect(circles[1].find('.nc-icon-svg-wrapper-stub').exists()).toBe(true)
		expect(circles[2].text()).toBe('3')
		expect(circles[3].text()).toBe('4')
	})
})
