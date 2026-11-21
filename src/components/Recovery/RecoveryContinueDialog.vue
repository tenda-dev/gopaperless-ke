<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Post-login prompt for users with a pending account recovery.

  The server determines whether recovery is pending and keeps the signing
  destination and account identity out of the frontend. Continuing delegates
  recovery to the server, after which the signing page's existing
  authorization gate remains authoritative.

  Dismissing the prompt hides it for the current tab without consuming or
  clearing the recovery cookie.
-->
<template>
	<NcDialog v-if="visible"
		:name="t('libresign', 'Continue signing?')"
		size="normal"
		@update:open="onDialogToggle">
		<div class="rcd">
			<p class="rcd__lead">
				{{ t('libresign', 'You have successfully signed in. Would you like to return to the document you were trying to sign?') }}
			</p>
			<div class="rcd__actions">
				<NcButton @click="dismiss">
					{{ t('libresign', 'Not now') }}
				</NcButton>
				<NcButton variant="primary" @click="proceed">
					{{ t('libresign', 'Continue to document') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>
<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineOptions({ name: 'RecoveryContinueDialog' })

const DISMISSED_KEY = 'gopaperless.recovery.dismissed'
const COMPLETE_PATH = '/apps/libresign/account/recover/complete'

const route = useRoute()

// Server-provided and boolean only. A missing key means nothing is pending.
const pending = loadState<boolean>('libresign', 'recovery_pending', false) === true

function readDismissed(): boolean {
	try {
		return window.sessionStorage.getItem(DISMISSED_KEY) === '1'
	} catch {
		return false
	}
}
function writeDismissed(): void {
	try {
		window.sessionStorage.setItem(DISMISSED_KEY, '1')
	} catch {
		// Storage unavailable: the in-memory flag below still closes the dialog.
	}
}

const dismissed = ref(readDismissed())

// Signing screens manage their own flow; showing account recovery over
// them would interrupt that flow. Keep their route patterns in sync
// with signRouteUuid.ts.
const onSigningRoute = computed(() => {
	const path = route?.path ?? ''
	return path.startsWith('/p/') || /^\/f\/sign(\/|$)/.test(path) || /^\/sign(\/|$)/.test(path)
})

const visible = computed(() => pending && !dismissed.value && !onSigningRoute.value)

function dismiss(): void {
	dismissed.value = true
	writeDismissed()
}
function onDialogToggle(value: boolean): void {
	if (!value) {
		dismiss()
	}
}
function proceed(): void {
	window.location.assign(generateUrl(COMPLETE_PATH))
}
</script>
<style scoped lang="scss">
.rcd {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 22px;

	&__lead {
		margin: 0;
		font-size: 0.95rem;
		line-height: 1.55;
		color: var(--color-main-text);
	}
	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 10px;
		margin-top: 4px;
	}
}
</style>
