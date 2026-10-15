<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="account-overview">
		<EntitlementsTable
			:product="overview.product"
			:owner-label="ownerLabel"
			:summary="overview.summary"
			:entitlements="overview.entitlements" />
		<PaymentHistoryTable :payments="overview.payments" @open="openPayment" />

		<PaymentDetailModal v-model="detailOpen" :payment-id="detailId" />
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { ref } from 'vue'
import EntitlementsTable from './EntitlementsTable.vue'
import PaymentHistoryTable from './PaymentHistoryTable.vue'
import PaymentDetailModal from './PaymentDetailModal.vue'
import type { AccountOverview } from './useAdminEntitlements'

const props = defineProps<{ overview: AccountOverview }>()

const ownerLabel = computed(() => props.overview.user.email || props.overview.user.displayName || props.overview.user.uid)

const detailOpen = ref(false)
const detailId = ref<number | null>(null)
function openPayment(id: number) {
	detailId.value = id
	detailOpen.value = true
}
</script>
