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
		@update:open="onOpenChange"
		@paid="onPaid" />

	<CertificateBanner
		v-if="userContext.certRequired && !showModal"
		@activate="openModal" />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import CertificatePayment from './CertificatePayment.vue'
import CertificateBanner from './CertificateBanner.vue'

import { useManagedDialog } from '@/composables/useManagedDialog'
import { useUserContextStore } from '@/store/userContext'

const userContext = useUserContextStore()

const showModal = ref(false)

/**
 * Allows one automatic presentation per page lifecycle. The coordinator grants
 * presentation permission when the ToS prerequisite is satisfied and no other
 * automatic dialog owns the slot. Once dismissed or paid, the automatic nudge
 * is consumed; the banner remains available for manual reopening. A page reload
 * resets the nudge.
 */
const nudgePending = ref(true)
const eligible = computed(() => userContext.certRequired && nudgePending.value)
const { canPresent } = useManagedDialog({ id: 'cert-gate', priority: 20, eligible })

watch(canPresent, (allowed) => {
	if (allowed && nudgePending.value) {
		showModal.value = true
	}
}, { immediate: true })

watch(
	() => userContext.certRequired,
	(required) => {
		if (!required) {
			// Access granted (or cleared) — collapse everything.
			showModal.value = false
		}
	},
	{ immediate: true },
)

function onOpenChange(open: boolean): void {
	showModal.value = open
	if (!open) {
		nudgePending.value = false
	}
}

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
	nudgePending.value = false
}
</script>
