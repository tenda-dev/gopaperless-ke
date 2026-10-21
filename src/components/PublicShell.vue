<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Shared public chrome for anonymous GoPaperless pages (the /p/upload landing and
  the /p/workflow preparation flow). It provides the self-contained `.pu` token
  context and reuses the landing's header (PublicUploadHeader → pu__bar) and
  ecosystem footer (PublicUploadEcosystem → pu__eco). The presence of `.pu` is
  what drops the Nextcloud public header/guest footer (see the
  `#body-public:has(.pu)` rule in src/style/global.scss), so the app never shows
  duplicate chrome.
-->
<template>
	<div class="pu">
		<div class="pu__fold">
			<PublicUploadHeader :theme="theme" :show-theme-toggle="false" @sign-in="gateToLogin" />
			<div class="pu__body"><slot /></div>
		</div>
		<PublicUploadEcosystem />
	</div>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

import PublicUploadEcosystem from './PublicUploadEcosystem.vue'
import PublicUploadHeader from './PublicUploadHeader.vue'

defineOptions({ name: 'PublicShell' })

const theme = 'light' as const

// Server-built login destination for the configured User OIDC provider (mimi.ke),
// provided as initial state by the page controller. Empty → default NC login.
const oidcLoginUrl = loadState<string>('libresign', 'public_upload_oidc_login_url', '')

/**
 * "Have an account? Sign in" gates to login, which returns the visitor to the
 * authenticated upload/request view — identical to the /p/upload landing.
 */
function gateToLogin(): void {
	if (oidcLoginUrl) {
		window.location.href = oidcLoginUrl
		return
	}
	const target = generateUrl('/apps/libresign/f/request')
	window.location.href = generateUrl('/login') + '?redirect_url=' + encodeURIComponent(target)
}
</script>

<style scoped lang="scss">
.pu {
	// Self-contained light palette (mirrors the landing's base `.pu` tokens), so
	// the shared header/footer render consistently regardless of the surrounding
	// Nextcloud theme. Dark mode is a later reconciliation pass.
	--ink: #0e1116;
	--slate: #5b6472;
	--line: #e6e8ec;
	--surface: #ffffff;
	--brand: #04d56d;
	--brand-strong: #03b95e;
	--on-brand: #0e1116;
	--eco-bg: #0f172a;
	--eco-fg: #e2e8f0;
	--eco-dim: #94a3b8;
	--eco-accent: #04d56d;
	--eco-edge: transparent;
	// Side gutter that also caps content at 1264px (the 1440 artboard minus gutters).
	--pu-pad: max(clamp(20px, 6vw, 88px), (100% - 1264px) / 2);
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 100%;
	font-family: var(--tenda-font-family, 'Space Grotesk', -apple-system, blinkmacsystemfont, 'Segoe UI', roboto, sans-serif);
	color: var(--ink);
	background: var(--surface);
	color-scheme: light;
}

// Header + body fill at least the first screen; the footer sits below.
.pu__fold {
	display: grid;
	grid-template-rows: auto 1fr;
	min-height: 100svh;
}

.pu__body {
	min-height: 0;
	display: flex;
	flex-direction: column;
}
</style>
