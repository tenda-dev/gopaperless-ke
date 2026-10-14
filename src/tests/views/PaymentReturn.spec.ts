/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import PaymentReturn from '@/views/PaymentReturn.vue'
import { verifyPayment } from '@/payment/api'
import { getPaymentFromUrl } from '@/payment/helpers'

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: { name: 'NcLoadingIcon', template: '<div />' },
}))

vi.mock('@/payment/api', () => ({
	verifyPayment: vi.fn(),
}))

vi.mock('@/payment/helpers', () => ({
	getPaymentFromUrl: vi.fn(() => ({ transactionToken: 'tok-1' })),
	clearPaymentParamsFromUrl: vi.fn(),
}))

vi.mock('@/services/toast', () => ({
	notifySuccess: vi.fn(),
	notifyError: vi.fn(),
}))

vi.mock('@/store/paymentContext', () => ({
	usePaymentContextStore: () => ({ reset: vi.fn() }),
}))

const resolveMock = vi.fn(({ query }: { name: string; query: { uuid: string } }) => ({
	href: `/index.php/apps/libresign/f/filelist/sign?uuid=${query.uuid}`,
}))

vi.mock('vue-router', () => ({
	useRouter: () => ({ resolve: resolveMock }),
}))

describe('PaymentReturn sponsorship-workflow navigation', () => {
	let replace: ReturnType<typeof vi.spyOn>

	beforeEach(() => {
		vi.clearAllMocks()
		// happy-dom's window.location is not reassignable; spy on replace so
		// no real navigation happens and we can assert the target.
		replace = vi.spyOn(window.location, 'replace').mockImplementation(() => {})
	})

	afterEach(() => {
		replace.mockRestore()
		vi.clearAllMocks()
	})

	it('navigates to the file (filelist/sign?uuid=) on verified success with a sponsorship-workflow uuid', async () => {
		;(verifyPayment as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
			status: 'SUCCESS',
			sponsorshipWorkflowFileUuid: 'file-uuid-xyz',
			returnUrl: `${window.location.origin}/f/request`,
		})

		mount(PaymentReturn)
		await flushPromises()
		await flushPromises()

		expect(resolveMock).toHaveBeenCalledWith({ name: 'fileslist', query: { uuid: 'file-uuid-xyz' } })
		expect(replace).toHaveBeenCalledWith('/index.php/apps/libresign/f/filelist/sign?uuid=file-uuid-xyz')
	})

	it('falls back to returnUrl on success when there is no sponsorship-workflow uuid', async () => {
		;(verifyPayment as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
			status: 'SUCCESS',
			sponsorshipWorkflowFileUuid: null,
			returnUrl: `${window.location.origin}/f/request`,
		})

		mount(PaymentReturn)
		await flushPromises()
		await flushPromises()

		expect(resolveMock).not.toHaveBeenCalled()
		expect(replace).toHaveBeenCalledWith('/f/request')
	})

	it('does NOT use the sponsorship-workflow nav on a non-success verification (uses returnUrl)', async () => {
		;(verifyPayment as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
			status: 'FAILED',
			sponsorshipWorkflowFileUuid: 'file-uuid-xyz',
			returnUrl: `${window.location.origin}/f/request`,
		})

		mount(PaymentReturn)
		await flushPromises()
		await flushPromises()

		expect(resolveMock).not.toHaveBeenCalled()
		expect(replace).toHaveBeenCalledWith('/f/request')
	})

	it('redirects to / when there is no transaction token', async () => {
		;(getPaymentFromUrl as unknown as ReturnType<typeof vi.fn>).mockReturnValueOnce({ transactionToken: null })

		mount(PaymentReturn)
		await flushPromises()

		expect(verifyPayment).not.toHaveBeenCalled()
		expect(replace).toHaveBeenCalledWith('/')
	})
})
