<!--
  - SPDX-FileCopyrightText: 2021 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent app-name="libresign" :class="{ 'sign-external-page': isSignExternalPage }">
		<LeftSidebar v-if="showLeftSidebar" />
		<NcAppContent :class="{ 'icon-loading': loading }">
			<DefaultPageError v-if="isDoNothingError" />
			<router-view v-else-if="!loading" :key="$route.name" v-model:loading="loading" />
			<NcEmptyContent v-if="isRoot"
				:description="t('libresign', 'GoPaperless, digital signature app for Tendaworld.')">
				<template #icon>
					<img :src="TendaworldSign">
				</template>
			</NcEmptyContent>
		</NcAppContent>
		<RightSidebar v-if="isLoggedIn" />
	</NcContent>
	<SponsorshipWorkflow v-if="isLoggedIn" />
	<CertificateGate v-if="isLoggedIn" />
	<TermsOfServiceModal
		:open="termsRequired"
		:body="termsBody"
		:loading="termsLoading"
		:error="termsError"
		@close="termsRequired = true">
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="termsLoading || termsSigning"
				@click="termsError ? checkTerms() : acceptTerms">
				{{ termsError ? t('libresign', 'Retry') : termsSigning ? t('libresign', 'Saving...') : t('libresign', 'I agree') }}
			</NcButton>
		</template>
	</TermsOfServiceModal>
	<Toaster richColors position="top-right" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

defineOptions({ name: 'LibreSign' })

import { useRoute } from 'vue-router'
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { Toaster } from 'vue-sonner'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import LeftSidebar from './components/LeftSidebar/LeftSidebar.vue'
import RightSidebar from './components/RightSidebar/RightSidebar.vue'
import SponsorshipWorkflow from './components/Sponsorship/SponsorshipWorkflow.vue'
import CertificateGate from './components/Certificate/CertificateGate.vue'
import DefaultPageError from './views/DefaultPageError.vue'
import TermsOfServiceModal from './components/TermsOfServiceModal.vue'

import { initialActionCode, ACTION_CODES } from './helpers/ActionMapping'
import TendaworldSign from '../img/tenda-icon.png'
import { useNetworkState } from '@/composables/useNetworkState'
import { useForceLightMode } from '@/composables/useForceLightMode'
import { useUserContextStore } from '@/store/userContext'

const route = useRoute()
const userContext = useUserContextStore()

const loading = ref(false)

// Anonymous visitors (e.g. the public upload landing) have no session.
// Authed chrome — left/right sidebars, cert gate, sponsorship all assume a
// user and eagerly hits authed endpoints (e.g. CreditsSummaryCard calls
// /account/me), so it must not render for guests.
const isLoggedIn = computed(() => !!getCurrentUser())

const isRoot = computed(() => route.path === '/')
const isSignExternalPage = computed(() => route.path.startsWith('/p/'))
const isDoNothingError = computed(() => initialActionCode.value === ACTION_CODES.DO_NOTHING)
const showLeftSidebar = computed(() => isLoggedIn.value && !route.matched.some(record => record.meta?.hideLeftSidebar === true))

type Term = { id: number; renderedBody: string; languageCode: string }
const termsRequired = ref(false)
const termsLoading = ref(false)
const termsSigning = ref(false)
const termsError = ref('')
const terms = ref<Term[]>([])
const termsBody = computed(() => {
	const language = (window?.OC?.getLanguage?.() ?? navigator.language ?? 'en').split('-')[0]
	return terms.value.find(term => term.languageCode === language)?.renderedBody ?? terms.value[0]?.renderedBody ?? ''
})

async function checkTerms() {
	termsLoading.value = true
	termsError.value = ''
	try {
		const response = await axios.get<{ ocs: { data: { terms: Term[]; hasSigned: boolean } } }>(
			generateOcsUrl('/apps/terms_of_service/terms'),
		)
		terms.value = response.data.ocs.data.terms ?? []
		termsRequired.value = terms.value.length > 0 && !response.data.ocs.data.hasSigned
	} catch (error: any) {
		termsError.value = error?.response?.data?.ocs?.data?.message ?? t('libresign', 'Could not load Terms of Service')
		termsRequired.value = true
	} finally {
		termsLoading.value = false
	}
}

async function acceptTerms() {
	termsSigning.value = true
	try {
		await Promise.all(terms.value.map(term => axios.post(
			generateOcsUrl('/apps/terms_of_service/sign'),
			{ termId: term.id },
		)))
		termsRequired.value = false
	} catch (error: any) {
		termsError.value = error?.response?.data?.ocs?.data?.message ?? t('libresign', 'Could not save Terms acceptance')
	} finally {
		termsSigning.value = false
	}
}

onMounted(async () => {
	useNetworkState()
	// Actively strip and block Nextcloud dark mode variations
	useForceLightMode()

	// Anonymous visitors (e.g. the public upload landing) have no session,
	// so /account/me would 401/404 on load. Only hydrate when a user is present;
	// the authed path is unchanged.
	if (getCurrentUser()) {
		await userContext.initialise()
		await checkTerms()
	}
})
</script>

<style lang="scss" scoped>
.sign-external-page {
	width: 100%;
	height: 100%;
	margin: unset;
	box-sizing: unset;
	border-radius: unset;
}

.app-libresign {
	.app-navigation {
		.app-navigation-entry.active {
			background-color: var(--color-primary-element) !important;

			.app-navigation-entry-link {
				color: var(--color-primary-element-text) !important;
			}
		}
	}
}

.app-content {
	background: #f6f8f7;

	.empty-content {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 70%;
		margin-top: unset !important;

		margin-top: 10vh;

		p {
			opacity: .6;
		}

		&__icon {
			width: 400px !important;
			height: unset !important;
		}
	}
}

</style>
