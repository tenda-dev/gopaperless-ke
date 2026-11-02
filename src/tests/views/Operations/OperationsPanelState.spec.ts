/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ t: (_a: string, text: string) => text }))

import OperationsPanelState from '../../../views/Operations/components/OperationsPanelState.vue'
import type { PanelStatus } from '../../../views/Operations/types.ts'

const mountIt = (status: PanelStatus, error: string | null = null) => mount(OperationsPanelState, {
	props: { status, error },
	slots: { default: '<div class="content">data</div>' },
	
})

describe('OperationsPanelState', () => {
	it('renders the slot only when ready', () => {
		expect(mountIt('ready').find('.content').exists()).toBe(true)
		for (const status of ['loading', 'empty', 'error'] as const) {
			expect(mountIt(status).find('.content').exists()).toBe(false)
		}
	})

	it('shows a busy skeleton while loading', () => {
		expect(mountIt('loading').find('[aria-busy="true"]').exists()).toBe(true)
	})

	it('error state offers a custom (non-Nextcloud) retry button that emits retry', async () => {
		const wrapper = mountIt('error', 'network')
		const retry = wrapper.find('button.ops-state__retry')

		expect(retry.exists()).toBe(true)
		await retry.trigger('click')
		expect(wrapper.emitted('retry')).toHaveLength(1)
	})

	it('does not offer retry when the server withdrew access or the range is invalid', () => {
		for (const code of ['Forbidden', 'range_too_large', 'invalid_range']) {
			expect(mountIt('error', code).find('button').exists()).toBe(false)
		}
	})

	it('empty state says so instead of showing zeros', () => {
		expect(mountIt('empty').text()).toContain('No activity in this period')
	})
})
