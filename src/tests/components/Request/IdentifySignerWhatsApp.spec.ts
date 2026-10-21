/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createL10nMock } from '../../testHelpers/l10n.js'
import IdentifySigner from '../../../components/Request/IdentifySigner.vue'
import { chooseWhatsApp, fetchWhatsAppAccount } from '../../../services/whatsapp'

let filesStore: any

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((_app: string, key: string, fallback: unknown) => key === 'whatsapp_enabled' ? true : fallback),
}))
vi.mock('@nextcloud/l10n', () => createL10nMock())
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../../services/toast', () => ({ showError: vi.fn() }))
vi.mock('../../../store/files.js', () => ({ useFilesStore: vi.fn(() => filesStore) }))
vi.mock('@/store/userContext', () => ({
	useUserContextStore: vi.fn(() => ({ uid: 'requester-uid', initialise: vi.fn().mockResolvedValue(undefined) })),
}))
vi.mock('../../../services/whatsapp', async (actual) => ({
	...(await actual<typeof import('../../../services/whatsapp')>()),
	fetchWhatsAppAccount: vi.fn(),
	chooseWhatsApp: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }))
vi.mock('@mdi/svg/svg/account.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('@mdi/svg/svg/email.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('@mdi/svg/svg/message-processing.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('@mdi/svg/svg/whatsapp.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('@mdi/svg/svg/xmpp.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('../../../../img/logo-signal-app.svg?raw', () => ({ default: '<svg></svg>' }))
vi.mock('../../../../img/logo-telegram-app.svg?raw', () => ({ default: '<svg></svg>' }))

const ann = { id: 'ann', method: 'account', identify: 'ann@example.com', displayName: 'Ann', acceptsEmailNotifications: true } as any

async function mountForm() {
	const wrapper = mount(IdentifySigner, {
		props: { signerToEdit: {}, methods: [{ name: 'account', friendly_name: 'Account' }] },
		global: {
			stubs: {
				NcButton: true,
				NcCheckboxRadioSwitch: true,
				NcIconSvgWrapper: true,
				NcLoadingIcon: true,
				NcNoteCard: true,
				NcTextArea: true,
				NcTextField: true,
				SignerSelect: true,
			},
		},
	}) as any
	await flushPromises() // onBeforeMount resets the selection after an await
	return wrapper
}

describe('IdentifySigner: Send sign request on Whatsapp', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		filesStore = {
			getFile: vi.fn(() => ({ id: 45, nodeType: 'file', signers: [{ signRequestId: 43, identifyMethods: [{ method: 'account', value: 'ann@example.com' }] }] })),
			saveOrUpdateSignatureRequest: vi.fn().mockResolvedValue({ success: true }),
			disableIdentifySigner: vi.fn(),
		}
	})

	it('checks for a number when an account is picked, and shows it masked', async () => {
		vi.mocked(fetchWhatsAppAccount).mockResolvedValue({ available: true, masked: '+254 7•• ••• 166', reason: null })
		const wrapper = await mountForm()

		wrapper.vm.applySelectedSigner(ann)
		expect(wrapper.vm.whatsAppChecking).toBe(true)
		await flushPromises()

		expect(fetchWhatsAppAccount).toHaveBeenCalledWith('ann@example.com')
		expect(wrapper.vm.whatsAppUsable).toBe(true)
		expect(wrapper.find('.whatsapp__hint').text()).toBe('Number on file: +254 7•• ••• 166')
		expect(wrapper.vm.whatsAppTo).toBe('+254 7•• ••• 166')
	})

	it('sends to the number on file while the field keeps it, and to a typed one once changed', async () => {
		vi.mocked(fetchWhatsAppAccount).mockResolvedValue({ available: true, masked: '+254 7•• ••• 166', reason: null })
		const wrapper = await mountForm()
		wrapper.vm.applySelectedSigner(ann)
		await flushPromises()

		wrapper.vm.whatsAppSend = true
		expect(wrapper.vm.whatsAppInvalid).toBe(false)
		await wrapper.vm.saveSigner()
		expect(chooseWhatsApp).toHaveBeenLastCalledWith(45, 43, '')

		// saving clears the form, so pick them again
		wrapper.vm.applySelectedSigner(ann)
		await flushPromises()
		wrapper.vm.whatsAppSend = true
		wrapper.vm.whatsAppTo = '0798 765 432'
		await wrapper.vm.saveSigner()
		expect(chooseWhatsApp).toHaveBeenLastCalledWith(45, 43, '0798 765 432')
	})

	it('needs a typed number when there is none on file, then saves the choice with it', async () => {
		vi.mocked(fetchWhatsAppAccount).mockResolvedValue({ available: false, masked: null, reason: 'no_number' })
		const wrapper = await mountForm()
		wrapper.vm.applySelectedSigner(ann)
		await flushPromises()

		wrapper.vm.whatsAppSend = true
		expect(wrapper.vm.whatsAppInvalid).toBe(true)
		wrapper.vm.whatsAppTo = '+1 415 555 0100'
		expect(wrapper.vm.whatsAppInvalid).toBe(true)
		wrapper.vm.whatsAppTo = '0798 765 432'
		expect(wrapper.vm.whatsAppInvalid).toBe(false)

		await wrapper.vm.saveSigner()

		expect(filesStore.saveOrUpdateSignatureRequest).toHaveBeenCalled()
		expect(chooseWhatsApp).toHaveBeenCalledWith(45, 43, '0798 765 432')
	})

	it('saves no choice when the switch stays off', async () => {
		vi.mocked(fetchWhatsAppAccount).mockResolvedValue({ available: true, masked: '+254 7•• ••• 166', reason: null })
		const wrapper = await mountForm()
		wrapper.vm.applySelectedSigner(ann)
		await flushPromises()

		await wrapper.vm.saveSigner()

		expect(chooseWhatsApp).not.toHaveBeenCalled()
	})

	it('is not offered for someone added by email', async () => {
		const wrapper = await mountForm()
		wrapper.vm.applySelectedSigner({ ...ann, method: 'email', identify: 'ann@example.com' })
		await flushPromises()

		expect(fetchWhatsAppAccount).not.toHaveBeenCalled()
		expect(wrapper.find('.whatsapp').exists()).toBe(false)
	})
})
