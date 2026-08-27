<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu">
		<PublicUploadHeader @sign-in="gateToLogin" />
		<PublicUploadHero :signed="signed" @get-started="gateToLogin" />
		<PublicUploadStages />
		<PublicUploadEcosystem @get-started="gateToLogin" />
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

import PublicUploadEcosystem from '../components/PublicUploadEcosystem.vue'
import PublicUploadHeader from '../components/PublicUploadHeader.vue'
import PublicUploadHero from '../components/PublicUploadHero.vue'
import PublicUploadStages from '../components/PublicUploadStages.vue'

defineOptions({ name: 'PublicUpload' })

const router = useRouter()
const signed = ref(false)

/**
 * PHP only runs on the full-page load, so a logged-in user who reaches this
 * view via in-SPA navigation is sent to the authed upload/request view.
 */
onMounted(() => {
	if (getCurrentUser()) {
		router.replace({ name: 'requestFiles' })
		return
	}

	const reduce = window.matchMedia('(prefers-reduced-motion:reduce)').matches
	if (reduce) {
		signed.value = true
		return
	}
	window.setTimeout(() => {
		signed.value = true
	}, 900)
})

/**
 * We gate before any file bytes: any action sends the visitor to the Nextcloud login
 * page, which returns them to the authenticated upload/request view.
 */
function gateToLogin(): void {
	// redirect_url must be a server-relative path: Nextcloud prepends the host itself
	const target = generateUrl('/apps/libresign/f/request')
	window.location.href = generateUrl('/login') + '?redirect_url=' + encodeURIComponent(target)
}
</script>

<style scoped lang="scss">
.pu {
	// Self-contained palette so the landing renders consistently regardless of
	// the surrounding Nextcloud theme (the app forces light mode).
	--ink: #0e1116;
	--slate: #5b6472;
	--faint: #9aa3af;
	--line: #e6e8ec;
	--line-soft: #eef0f3;
	--canvas: #f4f5f7;
	--surface: #ffffff;
	--brand: #04d56d;
	--brand-strong: #03b95e;
	--brand-wash: #e8fbf1;
	--brand-ring: rgba(4, 213, 109, .18);
	--eco-bg: #0f172a;
	--eco-fg: #e2e8f0;
	--eco-dim: #94a3b8;
	--eco-accent: #04d56d;
	// Monospace face for small technical labels (badges, page counts, kickers).
	--mono: ui-monospace, 'SFMono-Regular', menlo, monospace;
	--pu-max: 1440px;
	--pu-pad: clamp(20px, 4vw, 44px);
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 100%;
	font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, blinkmacsystemfont, 'Segoe UI', roboto, sans-serif);
	color: var(--ink);
	background: var(--surface);
	margin-bottom: -60px; // footer overlap

	b {
		font-weight: 600;
	}

	svg:not(.pu__sig) {
		fill: currentColor;
	}
}

.pu-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	width: 100%;
	padding: 11px 18px;
	border-radius: 10px;
	font: inherit;
	font-weight: 600;
	font-size: 14px;
	cursor: pointer;
	transition: transform .12s, background .2s;

	svg {
		width: 16px;
		height: 16px;
		fill: currentColor;
	}

	&:active {
		transform: translateY(1px);
	}

	&--primary {
		border: none;
		color: var(--ink);
		background: var(--brand);

		&:hover {
			background: var(--brand-strong);
		}
	}
}

@keyframes pu-draw {
	to {
		stroke-dashoffset: 0;
	}
}

@media (max-width: 860px) {
	.pu {
		&__hero-grid {
			flex-direction: column;
		}

		&__canvas {
			order: 2;
			min-height: 360px;
			border-inline-start: none;
			border-top: 1px solid var(--line-soft);
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.pu * {
		transition: none !important;
	}

	.pu__paper.is-signed .pu__sig path {
		animation: none;
		stroke-dashoffset: 0;
	}
}
</style>
