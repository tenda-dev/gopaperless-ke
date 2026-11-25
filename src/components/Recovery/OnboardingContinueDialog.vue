<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Presents a one-time prompt to resume signing after returning from Tendaworld
  onboarding.

  The server supplies only a validated signing path and consumes the recovery
  context when returning to the application. A session latch prevents the
  resume action from being triggered repeatedly during the same browser session,
  while the destination is validated again before navigation.
-->
<template>
	<NcDialog v-if="visible"
		:name="t('libresign', 'Continue signing?')"
		size="normal"
		@update:open="onDialogToggle">
		<div class="ocd">
			<p class="ocd__lead">
				{{ t('libresign', 'You are all set. Would you like to return to the document you were signing?') }}
			</p>
			<div class="ocd__actions">
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

import { useManagedDialog } from '../../composables/useManagedDialog'
import { isSigningPath } from '../../utils/signingPath'

defineOptions({ name: 'OnboardingContinueDialog' })

// Session-scoped recovery latch namespace. Both dismissal and resume use the same
// latch so a document cannot repeatedly prompt or trigger recovery in one session.
const LATCH_KEY_PREFIX = 'gopaperless.onboarding.resumed'

const route = useRoute()

// Server-provided signing path, read once. Validate it is a signing link so a
// malformed value can never become an arbitrary redirect.
const resumePath = (() => {
	const path = loadState<string>('libresign', 'onboarding_resume', '')
	return isSigningPath(path) ? path : ''
})()

// Scope the resume latch to the signing instance so recovering one document
// does not suppress onboarding recovery for a different document.
const latchKey = `${LATCH_KEY_PREFIX}:${resumePath.split('/').filter(Boolean).pop() ?? ''}`

function readLatched(): boolean {
	try {
		return window.sessionStorage.getItem(latchKey) === '1'
	} catch {
		return false
	}
}
function writeLatched(): void {
	try {
		window.sessionStorage.setItem(latchKey, '1')
	} catch {
		// Storage unavailable: the in-memory flag below still prevents re-show.
	}
}

const latched = ref(readLatched())
const dismissed = ref(false)

// Never prompt while already on a signing screen — it manages its own flow.
const onSigningRoute = computed(() => {
	const path = route?.path ?? ''
	return path.startsWith('/p/') || /^\/f\/sign(\/|$)/.test(path) || /^\/sign(\/|$)/.test(path)
})

const eligible = computed(() => resumePath !== '' && !latched.value && !dismissed.value && !onSigningRoute.value)
const { canPresent } = useManagedDialog({ id: 'onboarding', priority: 11, eligible })

const visible = computed(() => canPresent.value && eligible.value)

function dismiss(): void {
	// Persist the dismissal for this browser session, scoped to the signing
	// request via the same latch Continue writes, so a reload within the
	// recovery window does not re-prompt for this document. A new browser
	// session clears sessionStorage and may present the prompt again while the
	// recovery context is still valid.
	latched.value = true
	writeLatched()
	dismissed.value = true
}
function onDialogToggle(value: boolean): void {
	if (!value) {
		dismiss()
	}
}
function proceed(): void {
	// Latch before leaving so a return to this view cannot navigate again.
	latched.value = true
	writeLatched()
	window.location.assign(resumePath)
}
</script>
<style scoped lang="scss">
.ocd {
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
@media (max-width: 480px) {
	.ocd__actions {
		flex-direction: column;
		align-items: stretch;
	}

	.ocd__actions :deep(.button-vue) {
		width: 100%;
		max-width: 100%;
	}
}
</style>
