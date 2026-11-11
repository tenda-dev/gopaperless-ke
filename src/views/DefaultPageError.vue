<!--
  - SPDX-FileCopyrightText: 2021 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="error-page">
		<div class="logo-header">
			<img :src="logoLibreSign"
				:alt="t('libresign', 'LibreSign')"
				class="logo-icon">
		</div>

		<div class="error-container">
			<NcEmptyContent :name="title"
				:description="description">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="80" class="alert-icon" />
				</template>
				<template #action>
					<div v-if="errors.length" class="error-messages">
						<p v-if="maskedEmail" class="masked-email">
							{{ t('libresign', 'Sent to {email}', { email: maskedEmail }) }}
						</p>
						<NcNoteCard v-for="(error, index) in errors"
							:key="index"
							type="error">
							{{ error.message }}
						</NcNoteCard>
						<NcButton v-if="canSwitchAccount" variant="primary" @click="switchAccount">
							{{ t('libresign', 'Switch account') }}
						</NcButton>
					</div>
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script setup lang="ts">
import { getRequestToken } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { computed } from 'vue'

import {
	mdiAlertCircleOutline,
} from '@mdi/js'
import { t } from '@nextcloud/l10n'

import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'

import logoLibreSignAsset from '../../img/tenda-icon.png'
import { signUuidFromPath } from '../helpers/signRouteUuid'

defineOptions({
	name: 'DefaultPageError',
})

type ErrorRow = { message: string, switchAccount?: boolean, switchAccountPath?: string, maskedEmail?: string }

const logoLibreSign = logoLibreSignAsset

const errors = computed<ErrorRow[]>(() => {
	const loadedErrors = loadState<ErrorRow[]>('libresign', 'errors', [])
	if (loadedErrors.length) {
		return loadedErrors
	}
	const errorMessage = loadState<{ message?: string }>('libresign', 'error', {}).message
	if (errorMessage) {
		return [{ message: errorMessage }]
	}
	return []
})

const canSwitchAccount = computed(() => errors.value.some(error => error.switchAccount))
const maskedEmail = computed(() => errors.value.find(error => error.maskedEmail)?.maskedEmail ?? '')
const switchAccountPath = computed(() => errors.value.find(error => error.switchAccountPath)?.switchAccountPath ?? '')

/**
 * Starts the appropriate account-recovery path.
 *
 * Server-handled recovery posts to switchAccountPath and uses gp_recover
 * to return through authentication. Legacy CLIENT_SLS recovery retains
 * libresign_resume and the existing single-logout flow.
 */
function switchAccount() {
	// The server ends this session and sends the browser to the right login,
	// so this is a top-level form POST the browser follows, not an XHR.
	const uuid = signUuidFromPath(window.location.pathname)
	if (switchAccountPath.value && uuid) {
		const form = document.createElement('form')
		form.method = 'post'
		form.action = switchAccountPath.value
		for (const [name, value] of [['requesttoken', getRequestToken() ?? ''], ['uuid', uuid]]) {
			const input = document.createElement('input')
			input.type = 'hidden'
			input.name = name
			input.value = value
			form.appendChild(input)
		}
		document.body.appendChild(form)
		form.submit()
		return
	}
	document.cookie = `libresign_resume=${encodeURIComponent(window.location.pathname)}; path=/; max-age=600; SameSite=Lax`
	window.location.assign(generateUrl('/apps/user_oidc/sls'))
}

// Use a server-provided title when available; otherwise fall back to
// the generic error or page-not-found title.
const title = computed(() => {
	const overrideTitle = loadState<string>('libresign', 'title', '')
	if (overrideTitle) {
		return overrideTitle
	}
	if (maskedEmail.value) {
		return t('libresign', 'You\'re signed in to a different account')
	}
	return errors.value.length
		? t('libresign', 'An error occurred')
		: t('libresign', 'Page not found')
})

const description = computed(() => (errors.value.length
	? ''
	: t('libresign', 'Sorry but the page you are looking for does not exist, has been removed, moved or is temporarily unavailable.')))
</script>

<style lang="scss" scoped>
.error-page {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	min-height: 100vh;
	padding: 40px 20px;
	gap: 24px;
	background: var(--color-background-dark);

	.logo-header {
		.logo-icon {
			height: 80px;

			@media (max-width: 768px) {
				height: 60px;
			}
		}
	}

	.error-container {
		max-width: 800px;
		width: 100%;
		padding: 48px 32px;
		background: var(--color-main-background);
		border-radius: var(--border-radius-large);
		box-shadow: 0 2px 16px rgba(0, 0, 0, 0.1);

		:deep(.empty-content__action) {
			min-width: 500px;

			@media (max-width: 768px) {
				min-width: 100%;
			}
		}

		.error-messages {
			display: flex;
			flex-direction: column;
			gap: 12px;
			width: 100%;
		}

		.masked-email {
			margin: 0;
			text-align: center;
			color: var(--color-text-maxcontrast);
			font-family: 'IBM Plex Mono', monospace;
		}

		@media (max-width: 768px) {
			padding: 32px 24px;
		}
	}

	.alert-icon {
		color: #e53c3c;
	}
}
</style>
