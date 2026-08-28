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
		<RightSidebar />
	</NcContent>
	<NcModal v-if="termsRequired" :size="'large'" @close="termsRequired = true">
		<div class="terms-modal-content">
			<h2>{{ t('libresign', 'Terms of Service') }}</h2>
			<NcNoteCard v-if="termsError" type="error">{{ termsError }}</NcNoteCard>
			<NcLoadingIcon v-else-if="termsLoading" :size="32" />
			<div v-else class="terms-body" v-html="termsBody" />
			<NcButton
				variant="primary"
				:disabled="termsLoading || termsSigning"
				@click="termsError ? checkTerms() : acceptTerms">
				{{ termsError ? t('libresign', 'Retry') : termsSigning ? t('libresign', 'Saving...') : t('libresign', 'I agree') }}
			</NcButton>
		</div>
	</NcModal>
	<Toaster richColors position="top-right" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

defineOptions({ name: 'LibreSign' })

import { useRoute } from 'vue-router'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { Toaster } from 'vue-sonner'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import LeftSidebar from './components/LeftSidebar/LeftSidebar.vue'
import RightSidebar from './components/RightSidebar/RightSidebar.vue'
import DefaultPageError from './views/DefaultPageError.vue'

import { initialActionCode, ACTION_CODES } from './helpers/ActionMapping'
import TendaworldSign from '../img/tenda-icon.png'
import { useNetworkState } from '@/composables/useNetworkState'
import { useForceLightMode } from '@/composables/useForceLightMode'
import { useUserContextStore } from '@/store/userContext'

const route = useRoute()
const userContext = useUserContextStore()

const loading = ref(false)

const isRoot = computed(() => route.path === '/')
const isSignExternalPage = computed(() => route.path.startsWith('/p/'))
const isDoNothingError = computed(() => initialActionCode.value === ACTION_CODES.DO_NOTHING)
const showLeftSidebar = computed(() => !route.matched.some(record => record.meta?.hideLeftSidebar === true))

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

	await userContext.initialise()
	await checkTerms()
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

.terms-modal-content {
	padding: 24px;
	max-width: 760px;
}

.terms-body {
	max-height: 55vh;
	overflow: auto;
	margin: 16px 0;
}
</style>
