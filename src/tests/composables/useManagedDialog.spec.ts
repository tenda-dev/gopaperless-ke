/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'

import { useManagedDialog } from '../../composables/useManagedDialog'
import { useDialogCoordinatorStore } from '../../store/dialogCoordinator'

function host(id: string, priority: number, eligible: ReturnType<typeof ref<boolean>>) {
	return defineComponent({
		setup() {
			const { canPresent } = useManagedDialog({ id, priority, eligible: eligible as never })
			return () => h('span', { class: id }, String(canPresent.value))
		},
	})
}

describe('useManagedDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useDialogCoordinatorStore().setPrerequisite('accepted')
	})

	it('registers on mount and exposes canPresent', async () => {
		const w = mount(host('a', 10, ref(true)))
		await w.vm.$nextTick()
		expect(w.text()).toBe('true')
	})

	it('unregisters on unmount, releasing ownership', async () => {
		const store = useDialogCoordinatorStore()
		const w = mount(host('a', 10, ref(true)))
		await nextTick()
		expect(store.currentOwnerId).toBe('a')
		w.unmount()
		await nextTick()
		expect(store.currentOwnerId).toBeNull()
	})

	it('only the highest-priority of two sibling hosts can present, whatever the mount order', async () => {
		const A = host('a', 20, ref(true))
		const B = host('b', 10, ref(true))
		const Parent = defineComponent({ render: () => h('div', [h(A), h(B)]) })
		const w = mount(Parent)
		await nextTick()
		await nextTick()
		expect(w.find('.a').text()).toBe('false')
		expect(w.find('.b').text()).toBe('true')
	})

	it('is false while the prerequisite blocks', async () => {
		const store = useDialogCoordinatorStore()
		store.setPrerequisite('pending')
		const w = mount(host('a', 10, ref(true)))
		expect(w.text()).toBe('false')
	})
})
