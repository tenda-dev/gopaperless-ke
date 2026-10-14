<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="entitlement-admin">
		<div class="entitlement-admin__wrap">
			<header class="entitlement-admin__head">
				<span class="entitlement-admin__eyebrow">{{ t('libresign', 'Admin · Support tools') }}</span>
				<h2 class="entitlement-admin__title">{{ t('libresign', 'Entitlement grants') }}</h2>
				<p class="entitlement-admin__sub">
					{{ t('libresign', 'Find a user, review their document-signing credits and recent payment context, and grant signing credits when an operational issue blocks them.') }}
				</p>
			</header>

			<div v-if="!featureEnabled" class="entitlement-admin__notice">
				{{ t('libresign', 'This feature is currently disabled.') }}
			</div>
			<div v-else-if="!isAdmin" class="entitlement-admin__notice">
				{{ t('libresign', 'You need administrator access to use this page.') }}
			</div>

			<template v-else>
				<Transition name="age-swap" mode="out-in">
					<UserSearch v-if="searchOpen" key="search" :autofocus="true" @select="onSelect" />
					<SelectedUserPill v-else-if="selectedUser" key="pill" :user="selectedUser" @clear="clearUser" @grant="grantOpen = true" />
				</Transition>

				<section v-if="selectedUser && !searchOpen && loadingOverview" class="age-card age-state">
					<NcLoadingIcon :size="26" />
					<span>{{ t('libresign', 'Loading account…') }}</span>
				</section>

				<AccountOverview v-else-if="selectedUser && !searchOpen && overview" :key="selectedUser.uid" :overview="overview" />

				<GrantCreditDialog
					v-if="selectedUser && overview"
					v-model="grantOpen"
					:user="selectedUser"
					:current-available="overview.summary.availableUses"
					@granted="onGranted"
					@failed="refreshOverview" />
			</template>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { getCurrentUser } from '@nextcloud/auth'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import UserSearch from '@/components/AdminGrants/UserSearch.vue'
import SelectedUserPill from '@/components/AdminGrants/SelectedUserPill.vue'
import AccountOverview from '@/components/AdminGrants/AccountOverview.vue'
import GrantCreditDialog from '@/components/AdminGrants/GrantCreditDialog.vue'
import { notifyError } from '@/services/toast'
import { useAdminEntitlements, ocsError, type AccountOverview as Overview, type AdminUser, type GrantResult } from '@/components/AdminGrants/useAdminEntitlements'

const { getOverview } = useAdminEntitlements()

const featureEnabled = loadState('libresign', 'admin_entitlement_grants_enabled', false) as boolean
const isAdmin = getCurrentUser()?.isAdmin ?? false

const selectedUser = ref<AdminUser | null>(null)
const overview = ref<Overview | null>(null)
const loadingOverview = ref(false)
const grantOpen = ref(false)
const searchOpen = ref(true)

async function onSelect(user: AdminUser) {
	selectedUser.value = user
	searchOpen.value = false
	await refreshOverview()
}

function clearUser() {
	selectedUser.value = null
	overview.value = null
	grantOpen.value = false
	searchOpen.value = true
}

async function refreshOverview() {
	if (!selectedUser.value) {
		return
	}
	loadingOverview.value = true
	try {
		overview.value = await getOverview(selectedUser.value.uid)
	} catch (err) {
		notifyError({ message: ocsError(err, t('libresign', 'Could not load the account.')), important: true })
	} finally {
		loadingOverview.value = false
	}
}

function onGranted(result: GrantResult) {
	if (overview.value) {
		overview.value = { ...overview.value, summary: result.summary }
	}
	refreshOverview()
}
</script>

<style scoped lang="scss">
@use '../../components/AdminGrants/tokens' as agtokens;

/**
 * Token palette for the admin-grants surface, defined once on the view root and
 * inherited by every child (UserSearch, SelectedUserPill, the tables, the grant
 * dialog) through CSS custom-property inheritance.
 *
 * Restrained by design: surfaces are neutral Nextcloud tokens; GoPaperless green
 * is reserved for primary actions and focus. Status colour comes from the shared
 * --gp-success/-error tokens so pills stay legible (no washed-out green surfaces).
 */
.entitlement-admin {
	@include agtokens.age-tokens;

	min-height: 100%;
	color: var(--age-fg);

	&__wrap {
		max-width: 1040px;
		margin: 0 auto;
		padding-block: 28px;
		padding-inline: 16px;
	}

	&__head {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin-bottom: 20px;
	}

	&__eyebrow {
		font-family: var(--age-mono);
		font-size: 11px;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--age-fg-muted);
	}

	&__title {
		margin: 0;
		font-size: 24px;
		font-weight: 700;
		letter-spacing: -0.01em;
	}

	&__sub {
		margin: 0;
		font-size: 13.5px;
		color: var(--age-fg-muted);
		max-width: 62ch;
	}

	&__notice {
		background: var(--age-surface);
		border: 1px solid var(--age-border);
		border-radius: var(--age-radius);
		padding: 24px;
		font-size: 14px;
		color: var(--age-fg-muted);
		text-align: center;
	}
}

.age-state {
	display: flex;
	align-items: center;
	gap: 12px;
	color: var(--age-fg-muted);
	font-size: 13px;
	background: var(--age-surface);
	border: 1px solid var(--age-border);
	border-radius: var(--age-radius);
	box-shadow: var(--age-shadow);
	padding: 20px;
}

.age-swap-enter-active,
.age-swap-leave-active {
	transition: opacity 0.18s ease, transform 0.18s ease;
}
.age-swap-enter-from { opacity: 0; transform: translateY(4px); }
.age-swap-leave-to { opacity: 0; transform: translateY(-4px); }

@media (prefers-reduced-motion: reduce) {
	.age-swap-enter-active,
	.age-swap-leave-active { transition: none; }
}
</style>
