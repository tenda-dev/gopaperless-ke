<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar v-if="sidebarStore.activeTab.length > 0"
		ref="rightAppSidebar"
		:open="sidebarStore.isVisible"
		:name="sidebarName"
		:subtitle="subTitle"
		v-model:active="sidebarStore.activeTab"
		@update:active="handleUpdateActive"
		@close="closeSidebar">
		<NcAppSidebarTab v-if="showSign"
			id="sign-tab"
			:name="sidebarName">
			<SignTab />
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="showRequestSignatureTab"
			id="request-signature-tab"
			:name="sidebarName">
			<WorkflowRequestSignatureTab />
			 <!-- <RequestSignatureTab /> -->
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'

// import RequestSignatureTab from '../RightSidebar/RequestSignatureTab.vue'
import WorkflowRequestSignatureTab from './Workflow/WorkflowRequestSignatureTab.vue'
import SignTab from '../RightSidebar/SignTab.vue'

import { useFilesStore } from '../../store/files.js'
import { useSidebarStore } from '../../store/sidebar.js'
import { useSignStore } from '../../store/sign.js'

defineOptions({
	name: 'RightSidebar',
})

type SidebarRef = {
	$refs?: {
		tabs?: {
			activeTab?: string
		}
	}
}

const filesStore = useFilesStore()
const signStore = useSignStore()
const sidebarStore = useSidebarStore()
const route = useRoute()
const rightAppSidebar = ref<SidebarRef | null>(null)

const fileName = computed(() => filesStore.getSelectedFileView()?.name ?? '')
const opened = computed(() => sidebarStore.isVisible)
const subTitle = computed(() => {
	if (!opened.value) {
		return t('libresign', 'Enter who will receive the request')
	}
	return filesStore.getSubtitle()
})

const showRequestSignatureTab = computed(() => sidebarStore.activeTab === 'request-signature-tab')
const showSign = computed(() => sidebarStore.activeTab === 'sign-tab' && signStore.document !== undefined)

const sidebarName = computed(() => 'Document action centre')

watch(() => sidebarStore.activeTab, (newValue) => {
	if (rightAppSidebar.value?.$refs?.tabs) {
		rightAppSidebar.value.$refs.tabs.activeTab = newValue
	}
})

watch(() => route.name, (routeName) => {
	sidebarStore.handleRouteChange(routeName)
})

function handleUpdateActive(active: string) {
	sidebarStore.setActiveTab(active)
}

function closeSidebar() {
	sidebarStore.hideSidebar()
}

defineExpose({
	filesStore,
	signStore,
	sidebarStore,
	rightAppSidebar,
	fileName,
	opened,
	subTitle,
	showRequestSignatureTab,
	showSign,
	handleUpdateActive,
	closeSidebar,
})
</script>
<style lang="scss" scoped>
.app-sidebar__tab  {
	box-shadow: none !important;
}

@media (max-width: 512px) {
	/*
	 * Keep the sidebar bounded below the app header so the stepper remains
	 * visible and NcAppSidebarTab can scroll its content internally.
	 *
	 * Content-driven sizing previously allowed the absolutely positioned
	 * sidebar to grow upward from the bottom. Long documents could push its
	 * top off-screen and eliminate the internal overflow needed for scrolling.
	 *
	 * Retain top: 0 and height: 100% so the sidebar uses #content's available
	 * height, which is already offset below the app header. Do not use 100dvh:
	 * that would size it against the viewport rather than the content area.
	 */
	.app-sidebar {
		top: 0;
		bottom: auto;
		width: 100vw;
		height: 100%;
		:deep(.app-sidebar-tabs__content) {
			min-height: unset;
		}
	}
}
</style>
