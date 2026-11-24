/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createL10nMock } from './testHelpers/l10n.js'
import { flushPromises, mount } from '@vue/test-utils'

import { initialActionCode, ACTION_CODES } from '../helpers/ActionMapping'

type RouteState = {
	path: string
	name: string | undefined
	params: Record<string, unknown>
	matched: Array<{ meta?: Record<string, unknown> }>
}

const routeState: RouteState = {
	path: '/f/filelist/sign',
	name: 'fileslist',
	params: {},
	matched: [] as Array<{ meta?: Record<string, unknown> }>,
}

vi.mock('vue-router', async () => {
	const actual = await vi.importActual<typeof import('vue-router')>('vue-router')
	return {
		...actual,
		useRoute: () => routeState,
	}
})

vi.mock('@nextcloud/l10n', () => createL10nMock())

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'testuser', displayName: 'Test User' })),
}))

vi.mock('@nextcloud/vue/components/NcContent', () => ({
	default: { name: 'NcContent', template: '<div class="nc-content"><slot /></div>', props: ['appName'] },
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => ({
	default: { name: 'NcAppContent', template: '<div class="nc-app-content"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: { name: 'NcEmptyContent', template: '<div class="nc-empty-content"><slot /></div>', props: ['description'] },
}))

vi.mock('../components/LeftSidebar/LeftSidebar.vue', () => ({
	__esModule: true,
	default: { name: 'LeftSidebar', template: '<aside class="left-sidebar" />' },
}))
vi.mock('../components/RightSidebar/RightSidebar.vue', () => ({
	__esModule: true,
	default: { name: 'RightSidebar', template: '<div class="right-sidebar" />' },
}))
vi.mock('../components/Sponsorship/SponsorshipWorkflow.vue', () => ({
	__esModule: true,
	default: { name: 'SponsorshipWorkflow', template: '<div />' },
}))
vi.mock('../components/Certificate/CertificateGate.vue', () => ({
	__esModule: true,
	default: { name: 'CertificateGate', template: '<div />' },
}))
vi.mock('../components/PublicWorkflow/PublicWorkflowClaimDialog.vue', () => ({
	__esModule: true,
	default: { name: 'PublicWorkflowClaimDialog', template: '<div />' },
}))

import App from '../App.vue'
import { useDialogCoordinatorStore } from '../store/dialogCoordinator'

describe('App', () => {
	beforeEach(() => {
		routeState.path = '/f/filelist/sign'
		routeState.name = 'fileslist'
		routeState.params = {}
		routeState.matched = []
		initialActionCode.value = 0
	})

	it('resolves the ToS prerequisite once into the dialog coordinator for a signed-in user', async () => {
		const coordinator = useDialogCoordinatorStore()
		coordinator.setPrerequisite('unknown')

		mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})
		await flushPromises()

		// tos_app_enabled is absent in tests -> 'not-enabled' without any request.
		expect(coordinator.prerequisite).toBe('not-enabled')
	})

	it('shows left sidebar on regular internal routes', async () => {
		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: { name: 'LeftSidebar', template: '<aside class="left-sidebar" />' },
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		await flushPromises()
		expect(wrapper.find('.left-sidebar').exists()).toBe(true)
	})

	it('hides left sidebar on incomplete setup routes', () => {
		routeState.path = '/f/incomplete'
		routeState.name = 'Incomplete'
		routeState.matched = [{ meta: { hideLeftSidebar: true } }]

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: { name: 'LeftSidebar', template: '<aside class="left-sidebar" />' },
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.left-sidebar').exists()).toBe(false)
	})

	it('hides left sidebar on incomplete external route name', () => {
		routeState.path = '/p/incomplete'
		routeState.name = 'IncompleteExternal'
		routeState.matched = [{ meta: { hideLeftSidebar: true } }]

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: { name: 'LeftSidebar', template: '<aside class="left-sidebar" />' },
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.left-sidebar').exists()).toBe(false)
	})

	it('hides left sidebar on incomplete path before route name resolves', () => {
		routeState.path = '/f/incomplete'
		routeState.name = undefined
		routeState.matched = [{ meta: { hideLeftSidebar: true } }]

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: { name: 'LeftSidebar', template: '<aside class="left-sidebar" />' },
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.left-sidebar').exists()).toBe(false)
	})

	it('adds sign-external-page class on /p/ routes', () => {
		routeState.path = '/p/sign/some-uuid'

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { name: 'NcContent', template: '<div class="nc-content"><slot /></div>', props: ['appName'] },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.nc-content').classes()).toContain('sign-external-page')
	})

	it('does not add sign-external-page class on internal routes', () => {
		routeState.path = '/f/filelist/sign'

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { name: 'NcContent', template: '<div class="nc-content"><slot /></div>', props: ['appName'] },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.nc-content').classes()).not.toContain('sign-external-page')
	})

	it('shows DefaultPageError when initial action code is DO_NOTHING (2000)', () => {
		initialActionCode.value = ACTION_CODES.DO_NOTHING

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: { name: 'DefaultPageError', template: '<div class="default-page-error" />' },
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.default-page-error').exists()).toBe(true)
	})

	it('does not show DefaultPageError on normal routes', () => {
		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { template: '<div><slot /></div>' },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: { name: 'DefaultPageError', template: '<div class="default-page-error" />' },
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.default-page-error').exists()).toBe(false)
	})

	it('shows NcEmptyContent on root path', () => {
		routeState.path = '/'

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { name: 'NcEmptyContent', template: '<div class="nc-empty-content"><slot /></div>', props: ['description'] },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.nc-empty-content').exists()).toBe(true)
	})

	it('does not show NcEmptyContent on non-root paths', () => {
		routeState.path = '/f/filelist/sign'

		const wrapper = mount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppContent: { template: '<main><slot /></main>' },
					NcEmptyContent: { name: 'NcEmptyContent', template: '<div class="nc-empty-content"><slot /></div>', props: ['description'] },
					LeftSidebar: true,
					RightSidebar: true,
					SponsorshipWorkflow: true,
					CertificateGate: true,
					PublicWorkflowClaimDialog: true,
					DefaultPageError: true,
					RouterView: { template: '<div class="router-view" />' },
				},
			},
		})

		expect(wrapper.find('.nc-empty-content').exists()).toBe(false)
	})
	describe('recovery prompt mount', () => {
		function mountApp() {
			return mount(App, {
				global: {
					stubs: {
						NcContent: { template: '<div><slot /></div>' },
						NcAppContent: { template: '<main><slot /></main>' },
						NcEmptyContent: { template: '<div><slot /></div>' },
						LeftSidebar: true,
						RightSidebar: true,
						DefaultPageError: true,
						RecoveryContinueDialog: { name: 'RecoveryContinueDialog', template: '<i class="rcd-stub" />' },
						RouterView: { template: '<div class="router-view" />' },
					},
				},
			})
		}

		it('mounts the recovery prompt for a signed-in user on an internal route', () => {
			routeState.path = '/f/request'
			expect(mountApp().find('.rcd-stub').exists()).toBe(true)
		})

		it('does not mount the recovery prompt on external signing pages', () => {
			routeState.path = '/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10'
			expect(mountApp().find('.rcd-stub').exists()).toBe(false)
		})
	})
})
