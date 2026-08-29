/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import FilesNextRowMenu from '../../../views/FilesListNext/components/FilesNextRowMenu.vue'
import type { FilesNextRow } from '../../../views/FilesListNext/useFilesNextRows.ts'

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

const baseRow: FilesNextRow = {
	nodeId: 1,
	name: 'Test.pdf',
	statusCode: 0,
	statusLabel: 'none',
	requester: '',
	uuid: 'uuid-1',
	signed: false,
	requestDate: '',
	signDate: '',
	expireDate: '',
	fileType: 'pdf',
} as unknown as FilesNextRow

const baseItem = {
	id: 'open',
	label: 'Open',
	icon: 'M12 2L2 22h20L12 2z',
	run: vi.fn(),
}

function createWrapper(mobile: boolean) {
	return mount(FilesNextRowMenu, {
		props: {
			open: true,
			row: baseRow,
			items: [baseItem],
			x: 100,
			y: 100,
			mobile,
		},
		global: {
			stubs: {
				Teleport: true,
			},
		},
	})
}

describe('FilesNextRowMenu.vue', () => {
	let wrapper: ReturnType<typeof createWrapper>

	afterEach(() => {
		wrapper?.unmount()
	})

	it('emits close on window resize when open on desktop', async () => {
		wrapper = createWrapper(false)

		window.dispatchEvent(new Event('resize'))
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('does not emit close on window resize when open on mobile', async () => {
		wrapper = createWrapper(true)

		window.dispatchEvent(new Event('resize'))
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
