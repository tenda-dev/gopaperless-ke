<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="cert-card" :class="`cert-card--${state}`">
		<div class="cert-card__header">
			<span class="cert-card__title">
				<NcIconSvgWrapper :path="mdiCertificateOutline" :size="18" />
				<span>{{ t('libresign', 'Personal Digital Certificate') }}</span>
			</span>

			<span class="cert-card__pill" :class="`cert-card__pill--${state}`">
				{{ statusLabel }}
			</span>
		</div>

		<p class="cert-card__holder">{{ holderName }}</p>

		<div class="cert-card__meta">
			<div class="cert-card__meta-item">
				<span class="cert-card__meta-label">{{ t('libresign', 'Issued by') }}</span>
				<span class="cert-card__meta-value">{{ issuer }}</span>
			</div>
			<div class="cert-card__meta-item">
				<!--
					"Access until", NOT "Valid until": this is the entitlement
					window (valid_until, derived from the admin validity-days
					config), deliberately decoupled from the certificate's
					X.509 crypto expiry.
				-->
				<span class="cert-card__meta-label">{{ t('libresign', 'Access until') }}</span>
				<span
					class="cert-card__meta-value"
					:class="{ 'cert-card__meta-value--muted': !validUntil }">
					{{ formattedValidUntil }}
				</span>
			</div>
		</div>

		<div v-if="showRenew" class="cert-card__actions">
			<button
				type="button"
				class="cert-card__renew"
				@click="emit('renew')">
				{{ t('libresign', 'Renew') }}
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { mdiCertificateOutline } from '@mdi/js'

import { t } from '@nextcloud/l10n'
import { getCanonicalLocale } from '@nextcloud/l10n'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

type CertificateState = 'unissued' | 'active' | 'expired'

const props = withDefaults(defineProps<{
	state: CertificateState
	holderName: string
	issuer?: string
	validUntil?: string | null
	showActions?: boolean
}>(), {
	issuer: 'JuliCA Certificate Authority',
	validUntil: null,
	showActions: false,
})

const emit = defineEmits([
	'renew',
])

const EM_DASH = '—'

const statusLabel = computed(() => {
	switch (props.state) {
	case 'active':
		return t('libresign', 'Active')
	case 'expired':
		return t('libresign', 'Expired')
	default:
		return t('libresign', 'Not issued')
	}
})

// Renew is the only action in v1, and only for an expired certificate.
const showRenew = computed(() => props.showActions && props.state === 'expired')

const formattedValidUntil = computed(() => {
	if (!props.validUntil) {
		return EM_DASH
	}

	const date = new Date(props.validUntil)
	if (Number.isNaN(date.getTime())) {
		return EM_DASH
	}

	return date.toLocaleDateString(getCanonicalLocale(), {
		day: '2-digit',
		month: 'short',
		year: 'numeric',
	})
})
</script>

<style lang="scss" scoped>
.cert-card {
	display: flex;
	flex-direction: column;
	gap: 12px;

	padding: 16px;

	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);

	// State: unissued — an empty slot waiting to be filled.
	&--unissued {
		border: 1px dashed var(--color-border-dark);
		background: var(--color-background-hover);
	}

	&--active {
		border: 1px solid var(--color-border);
	}

	&--expired {
		border: 1px solid var(--color-error);
	}

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__title {
		display: inline-flex;
		align-items: center;
		gap: 8px;

		font-size: 13px;
		font-weight: 500;
		color: var(--color-text-maxcontrast);

		:deep(svg) {
			color: var(--color-text-maxcontrast);
		}
	}

	&__pill {
		padding: 2px 10px;

		font-size: 11.5px;
		font-weight: 600;
		line-height: 1.5;

		border-radius: 999px;

		&--unissued {
			color: var(--color-text-maxcontrast);
			background: color-mix(in srgb, var(--color-text-maxcontrast) 14%, transparent);
		}

		&--active {
			color: var(--color-success);
			background: color-mix(in srgb, var(--color-success) 14%, transparent);
		}

		&--expired {
			color: var(--color-warning);
			background: color-mix(in srgb, var(--color-warning) 18%, transparent);
		}
	}

	&__holder {
		margin: 0;

		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__meta {
		display: flex;
		gap: 40px;
	}

	&__meta-item {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__meta-label {
		font-size: 11.5px;
		color: var(--color-text-maxcontrast);
	}

	&__meta-value {
		font-size: 13px;
		color: var(--color-main-text);

		&--muted {
			color: var(--color-text-maxcontrast);
		}
	}

	&__actions {
		display: flex;
		gap: 16px;

		padding-top: 4px;
		border-top: 1px solid var(--color-border);
	}

	&__renew {
		padding: 4px 0;

		font-size: 13px;
		font-weight: 500;

		color: var(--color-primary-element);
		background: transparent;
		border: none;
		cursor: pointer;

		&:hover {
			text-decoration: underline;
		}
	}
}
</style>
