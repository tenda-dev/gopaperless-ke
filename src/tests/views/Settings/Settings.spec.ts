/*
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import Settings from '../../../views/Settings/Settings.vue'

describe('Settings.vue', () => {
	it('renders the main settings sections container', () => {
		const wrapper = mount(Settings, {
			global: {
				stubs: {
					CertificateEngine: true,
					SignatureEngine: true,
					DownloadBinaries: true,
					ConfigureCheck: true,
					RootCertificateCfssl: true,
					RootCertificateOpenSsl: true,
					IdentificationFactors: true,
					ExpirationRules: true,
					Validation: true,
					CrlValidation: true,
					DocMDP: true,
					SignatureFlow: true,
					SigningMode: true,
					AllowedGroups: true,
					LegalInformation: true,
					IdentificationDocuments: true,
					CollectMetadata: true,
					SignatureStamp: true,
					SignatureHashAlgorithm: true,
					DefaultUserFolder: true,
					Envelope: true,
					Reminders: true,
					TSA: true,
					Confetti: true,
					FreeCredits: true,
					OneTimeSigning: true,
					SponsorshipSettings: true,
					FilesListNext: true,
					VisibleElementsNext: true,
				},
			},
		})

		expect(wrapper.findAllComponents({ name: 'SignatureEngine' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'SponsorshipSettings' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'Reminders' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'FreeCredits' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'OneTimeSigning' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'FilesListNext' })).toHaveLength(1)
		expect(wrapper.findAllComponents({ name: 'VisibleElementsNext' })).toHaveLength(1)
	})

	it('does not render SigningMode because the template gate is false', () => {
		const wrapper = mount(Settings, {
			global: {
				stubs: {
					CertificateEngine: true,
					SignatureEngine: true,
					DownloadBinaries: true,
					ConfigureCheck: true,
					RootCertificateCfssl: true,
					RootCertificateOpenSsl: true,
					IdentificationFactors: true,
					ExpirationRules: true,
					Validation: true,
					CrlValidation: true,
					DocMDP: true,
					SignatureFlow: true,
					SigningMode: { name: 'SigningMode', template: '<div class="signing-mode-stub" />' },
					AllowedGroups: true,
					LegalInformation: true,
					IdentificationDocuments: true,
					CollectMetadata: true,
					SignatureStamp: true,
					SignatureHashAlgorithm: true,
					DefaultUserFolder: true,
					Envelope: true,
					Reminders: true,
					TSA: true,
					Confetti: true,
					FreeCredits: true,
					OneTimeSigning: true,
					SponsorshipSettings: true,
					FilesListNext: true,
					VisibleElementsNext: true,
				},
			},
		})

		expect(wrapper.find('.signing-mode-stub').exists()).toBe(false)
	})
})
