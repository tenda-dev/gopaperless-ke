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
	<SponsorshipWorkflow />
	<CertificateGate />
	<Toaster richColors position="top-right" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

defineOptions({ name: 'LibreSign' })

import { useRoute } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { Toaster } from 'vue-sonner'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import LeftSidebar from './components/LeftSidebar/LeftSidebar.vue'
import RightSidebar from './components/RightSidebar/RightSidebar.vue'
import SponsorshipWorkflow from './components/Sponsorship/SponsorshipWorkflow.vue'
import CertificateGate from './components/Certificate/CertificateGate.vue'
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

onMounted(async () => {
	useNetworkState()
	// Actively strip and block Nextcloud dark mode variations
	useForceLightMode()

	await userContext.initialise()
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
