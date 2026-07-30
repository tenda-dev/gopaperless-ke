<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!--
		Global certificate gate controller (mirrors SponsorshipWorkflow).

		Everything keys off userContext.certRequired — the single source of
		truth, guarded on `initialised` and fail-open pre-hydration, so the
		paywall only ever appears once the user is confirmed to need it.
		With the admin kill-switch off, certRequired is never true and
		neither child ever mounts.
	-->
	<CertificatePayment
		v-if="userContext.certRequired"
		:open="showModal"
		@update:open="showModal = $event"
		@paid="onPaid" />

	<CertificateBanner
		v-if="userContext.certRequired && !showModal"
		@activate="openModal" />
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

import CertificatePayment from './CertificatePayment.vue'
import CertificateBanner from './CertificateBanner.vue'

import { useUserContextStore } from '@/store/userContext'

const userContext = useUserContextStore()

const showModal = ref(false)

/**
 * Guards the one-time post-login nudge. Once the user has been shown the
 * modal automatically, dismissing it drops to the persistent banner
 * rather than re-opening the modal.
 */
const hasNudged = ref(false)

watch(
	() => userContext.certRequired,
	(required) => {
		if (required && !hasNudged.value) {
			// Post-login detection: nudge once.
			showModal.value = true
			hasNudged.value = true
		} else if (!required) {
			// Access granted (or cleared) — collapse everything.
			showModal.value = false
		}
	},
	{ immediate: true },
)

function openModal(): void {
	showModal.value = true
}

/**
 * Payment settled AND the store refresh resolved inside the modal, so
 * certRequired has already recomputed to false and both children unmount
 * reactively. Just tidy local state.
 */
function onPaid(): void {
	showModal.value = false
}
</script>
