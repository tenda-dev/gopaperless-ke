<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu">
		<header class="pu__bar">
			<div class="pu__inner pu__bar-row">
				<div class="pu__brand">
					<b class="pu__brand-name">GoPaperless</b>
					<span class="pu__brand-by">
						by <img class="pu__brand-by-logo" :src="TendaLogo" alt="Tendaworld">
					</span>
				</div>
				<button type="button" class="pu__signin" @click="gateToLogin">
					Have an account? <span>Sign in</span>
				</button>
			</div>
		</header>

		<section class="pu__hero">
			<div class="pu__inner pu__hero-grid">
				<div class="pu__intro">
					<div class="pu__kicker">Digital Signatures</div>
					<h1 class="pu__title">Get a document signed</h1>
					<p class="pu__lede">
						Upload a PDF, add your signers, and mark where each one signs.
						Send the request, track every signer, and get a sealed,
						verifiable record when it's done.
					</p>

					<div class="pu__drop">
						<div class="pu__drop-t">Start with your PDF</div>
						<div class="pu__drop-h">you'll sign in securely to continue</div>

						<button type="button" class="pu-btn pu-btn--primary" @click="gateToLogin">
							<svg viewBox="0 0 24 24"><path :d="mdiTrayArrowUp" /></svg>
							Upload document
						</button>
					</div>
				</div>

				<!-- Decorative: animates once on mount. -->
				<div class="pu__canvas" aria-hidden="true">
					<div class="pu__stack">
						<div class="pu__paper-back" />
						<div class="pu__paper" :class="{ 'is-signed': signed }">
							<div class="pu__doc-meta">
								<div class="pu__doc-name">
									<div class="pu__doc-badge">PDF</div>
									<div>
										<div class="pu__doc-t">Service Agreement</div>
										<div class="pu__doc-p">4 pages · 2 signers</div>
									</div>
								</div>
								<div class="pu__doc-status">{{ signed ? 'SIGNED' : 'DRAFT' }}</div>
							</div>
							<div class="pu__lines"><i /><i /><i /><i /></div>
							<div class="pu__field">
								<span class="pu__field-label">Signature</span>
								<span class="pu__field-hint">Sign here</span>
								<svg class="pu__sig" viewBox="0 0 150 34"><path d="M4 24 C10 6, 16 6, 20 20 S30 34, 36 18 S46 4, 52 22 C56 30, 62 26, 68 18 C74 10, 82 12, 90 20 C98 28, 108 22, 118 14 C126 8, 134 12, 146 20" /></svg>
							</div>
							<div class="pu__signed-tag">
								<svg viewBox="0 0 24 24"><path :d="mdiShieldCheckOutline" /></svg>
								<span><b>Signed &amp; sealed</b> · verifiable record kept</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>

		<div class="pu__rail">
			<div class="pu__inner pu__rail-row">
				<template v-for="(stage, i) in stages" :key="stage.name">
					<div class="pu__stage">
						<div class="pu__stage-ic">
							<svg viewBox="0 0 24 24"><path :d="stage.icon" /></svg>
						</div>
						<div class="pu__stage-tx">
							<b>{{ stage.name }}</b>
							<span>{{ stage.sub }}</span>
						</div>
					</div>
					<div v-if="i < stages.length - 1" class="pu__conn" />
				</template>
			</div>
		</div>

		<div class="pu__eco">
			<div class="pu__inner pu__eco-cols">
				<div class="pu__eco-about">
					<div class="pu__eco-kicker">About</div>
					<p class="pu__eco-lede">
						GoPaperless is the premier digital signature solution built for the African market, and part of the Tendaworld trust platform. Sign, send, and manage legally binding documents in minutes.
					</p>
					<a class="pu__eco-more" href="https://tendaworld.com/gopaperless" target="_blank" rel="noopener">
						More about GoPaperless
						<svg viewBox="0 0 24 24"><path :d="mdiArrowTopRight" /></svg>
					</a>
				</div>
				<div class="pu__eco-platform">
					<div class="pu__eco-kicker">Powered by</div>
					<a v-for="p in ecosystem" :key="p.name" class="pu__eco-item" :href="p.href" target="_blank" rel="noopener">
						<span class="pu__eco-logo">
							<svg viewBox="0 0 24 24"><path :d="p.icon" /></svg>
						</span>
						<span class="pu__eco-txt">
							<b>{{ p.name }}</b>
							<span>{{ p.sub }}</span>
						</span>
						<svg class="pu__eco-out" viewBox="0 0 24 24"><path :d="mdiArrowTopRight" /></svg>
					</a>
				</div>
			</div>

			<div class="pu__inner pu__eco-cta">
				<button type="button" class="pu__cta-btn" @click="gateToLogin">
					<span>Ready to go paperless? <b>Sign in to upload</b></span>
					<svg viewBox="0 0 24 24"><path :d="mdiArrowRight" /></svg>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import {
	mdiTrayArrowUp,
	mdiPlusBoxOutline,
	mdiAccountMultiplePlusOutline,
	mdiSendOutline,
	mdiShieldCheckOutline,
	mdiShieldLockOutline,
	mdiArrowTopRight,
	mdiArrowRight,
} from '@mdi/js'

import TendaLogo from '../../img/tenda-logo-green-updated.png'

defineOptions({ name: 'PublicUpload' })

const router = useRouter()

const signed = ref(false)

// Themed instance-logo URL. Kept intentionally to use later pending confirmation;
// the header currently renders the "GoPaperless" wordmark as HTML text instead.
const logoUrl = ref(generateUrl('/apps/theming/image/logo') + '?useSvg=1')

const stages = [
	{ name: 'Upload', sub: 'PDF · pages detected', icon: mdiTrayArrowUp },
	{ name: 'Add signers', sub: 'who signs, in order', icon: mdiAccountMultiplePlusOutline },
	{ name: 'Place signatures', sub: 'where each signer signs', icon: mdiPlusBoxOutline },
	{ name: 'Send', sub: 'request goes out', icon: mdiSendOutline },
]

const ecosystem = [
	{ name: 'JuliCA™', sub: 'the PKI that seals & verifies every document', href: 'https://tendaworld.com/julica', icon: mdiShieldCheckOutline },
	{ name: 'SecurySign™', sub: 'identity-verified digital signing', href: 'https://tendaworld.com/securysign', icon: mdiShieldLockOutline },
]

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

	&__inner {
		width: 100%;
		max-width: var(--pu-max);
		margin-inline: auto;
	}

	&__bar {
		width: 100%;
		border-bottom: 1px solid var(--line-soft);
	}

	&__bar-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 20px var(--pu-pad);
	}

	&__brand {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__brand-name {
		font-size: 20px;
		font-weight: 700;
		letter-spacing: -.01em;
		line-height: 1;
	}

	&__brand-by {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		font-family: var(--mono);
		font-size: 10px;
		letter-spacing: .02em;
		color: var(--faint);
	}

	&__brand-by-logo {
		display: block;
		width: auto;
		height: 13px;
	}

	&__signin {
		border: none;
		background: none;
		padding: 0;
		font: inherit;
		font-size: 13.5px;
		color: var(--slate);
		cursor: pointer;

		span {
			color: var(--brand-strong);
			font-weight: 600;
		}
	}

	&__hero {
		width: 100%;
	}

	&__hero-grid {
		display: flex;
		flex-wrap: wrap;
	}

	&__intro {
		flex: 1 1 380px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		padding: clamp(24px, 3.5vw, 52px) var(--pu-pad);
	}

	&__kicker {
		margin-bottom: 18px;
		font-family: var(--mono);
		font-weight: 600;
		font-size: 11.5px;
		letter-spacing: .16em;
		text-transform: uppercase;
		color: var(--brand-strong);
	}

	&__title {
		margin-bottom: 14px;
		font-size: clamp(28px, 3.4vw, 40px);
		font-weight: 700;
		line-height: 1.06;
		letter-spacing: -.03em;
	}

	&__lede {
		margin-bottom: 28px;
		max-width: 40ch;
		font-size: 15.5px;
		line-height: 1.6;
		color: var(--slate);
	}

	&__drop {
		padding: 22px;
		text-align: center;
		border: 1.5px dashed color-mix(in srgb, var(--brand) 40%, transparent);
		border-radius: 14px;
		background: var(--brand-wash);
		transition: border-color .2s, box-shadow .2s;

		&:hover {
			border-color: var(--brand);
			box-shadow: 0 0 0 4px var(--brand-ring);
		}
	}

	&__drop-t {
		margin-bottom: 3px;
		font-weight: 600;
		font-size: 15px;
	}

	&__drop-h {
		margin-bottom: 16px;
		font-family: var(--mono);
		font-size: 11.5px;
		letter-spacing: .02em;
		color: var(--faint);
	}

	&__canvas {
		flex: 1 1 400px;
		display: grid;
		place-items: center;
		min-height: 440px;
		padding: clamp(28px, 4vw, 52px);
		border-left: 1px solid var(--line-soft);
		background: radial-gradient(120% 120% at 70% 10%, #fff 0%, var(--canvas) 60%);
	}

	&__stack {
		position: relative;
		width: min(360px, 82%);
	}

	&__paper-back {
		position: absolute;
		inset: 14px -12px -12px 14px;
		border: 1px solid var(--line);
		border-radius: 10px;
		background: #fff;
		opacity: .6;
	}

	&__paper {
		position: relative;
		padding: 26px 24px 22px;
		border: 1px solid var(--line);
		border-radius: 11px;
		background: #fff;
		box-shadow: 0 26px 60px -34px rgba(14, 17, 22, .4);
	}

	&__doc-meta {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 20px;
	}

	&__doc-name {
		display: flex;
		align-items: center;
		gap: 9px;
	}

	&__doc-badge {
		display: grid;
		place-items: center;
		width: 30px;
		height: 36px;
		border: 1px solid #f5d4d4;
		border-radius: 5px;
		background: #fdecec;
		font-family: var(--mono);
		font-weight: 600;
		font-size: 8.5px;
		color: #c0392b;
	}

	&__doc-t {
		font-weight: 600;
		font-size: 13px;
	}

	&__doc-p {
		margin-top: 2px;
		font-family: var(--mono);
		font-size: 10.5px;
		color: var(--faint);
	}

	&__doc-status {
		font-family: var(--mono);
		font-size: 10px;
		font-weight: 600;
		letter-spacing: .04em;
		color: var(--faint);
	}

	&__lines {
		display: flex;
		flex-direction: column;
		gap: 9px;
		margin-bottom: 26px;

		i {
			display: block;
			height: 7px;
			border-radius: 4px;
			background: var(--line-soft);

			&:nth-child(1) { width: 100%; }
			&:nth-child(2) { width: 92%; }
			&:nth-child(3) { width: 78%; }
			&:nth-child(4) { width: 88%; }
		}
	}

	&__field {
		position: relative;
		padding: 12px 14px;
		border: 1.5px dashed color-mix(in srgb, var(--brand) 55%, transparent);
		border-radius: 9px;
		background: var(--brand-wash);
		transition: background .4s, border-color .4s;
	}

	&__field-label {
		position: absolute;
		top: -8px;
		left: 12px;
		padding: 0 6px;
		background: #fff;
		font-family: var(--mono);
		font-size: 9px;
		font-weight: 600;
		letter-spacing: .1em;
		text-transform: uppercase;
		color: var(--brand-strong);
	}

	&__field-hint {
		font-family: var(--mono);
		font-size: 11px;
		color: var(--brand-strong);
		opacity: .75;
		transition: opacity .3s;
	}

	&__sig {
		position: absolute;
		left: 16px;
		top: 8px;
		width: 150px;
		height: 34px;

		path {
			fill: none;
			stroke: var(--ink);
			stroke-width: 2.2;
			stroke-linecap: round;
			stroke-linejoin: round;
			stroke-dasharray: 520;
			stroke-dashoffset: 520;
		}
	}

	&__signed-tag {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-top: 12px;
		font-size: 11.5px;
		color: var(--slate);
		opacity: 0;
		transform: translateY(3px);
		transition: opacity .4s .2s, transform .4s .2s;

		svg {
			width: 14px;
			height: 14px;
			color: var(--brand-strong);
		}
	}

	&__paper.is-signed {
		.pu__field {
			border-style: solid;
			border-color: color-mix(in srgb, var(--brand) 35%, transparent);
			background: #fff;
		}

		.pu__field-hint {
			opacity: 0;
		}

		.pu__doc-status {
			color: var(--brand-strong);
		}

		.pu__signed-tag {
			opacity: 1;
			transform: none;
		}

		.pu__sig path {
			animation: pu-draw 2.1s cubic-bezier(.6, 0, .2, 1) forwards;
		}
	}

	&__rail {
		width: 100%;
		border-top: 1px solid var(--line-soft);
	}

	&__rail-row {
		display: flex;
		align-items: center;
		padding: clamp(22px, 3vw, 32px) var(--pu-pad);
	}

	&__stage {
		display: flex;
		align-items: center;
		gap: 10px;
		flex: none;
		min-width: 0;
	}

	&__stage-ic {
		display: grid;
		place-items: center;
		flex: none;
		width: 34px;
		height: 34px;
		border: 1px solid var(--line);
		border-radius: 9px;
		background: var(--canvas);
		color: var(--slate);

		svg {
			width: 16px;
			height: 16px;
		}
	}

	&__stage-tx {
		b {
			display: block;
			font-size: 13px;
		}

		span {
			font-family: var(--mono);
			font-size: 10px;
			letter-spacing: .02em;
			color: var(--faint);
		}
	}

	&__conn {
		flex: 1;
		height: 1px;
		min-width: 16px;
		margin: 0 16px;
		background: var(--line);
	}

	&__eco {
		width: 100%;
		background: var(--eco-bg);
	}

	&__eco-cols {
		display: flex;
		flex-wrap: wrap;
		gap: clamp(24px, 4vw, 56px);
		padding: clamp(24px, 3.5vw, 40px) var(--pu-pad) clamp(20px, 2.5vw, 28px);
	}

	&__eco-about {
		flex: 1 1 320px;
	}

	&__eco-platform {
		flex: 1 1 300px;
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__eco-kicker {
		margin-bottom: 14px;
		font-family: var(--mono);
		font-weight: 600;
		font-size: 10.5px;
		letter-spacing: .14em;
		text-transform: uppercase;
		color: var(--eco-dim);
	}

	&__eco-lede {
		margin-bottom: 16px;
		max-width: 46ch;
		font-size: 14px;
		line-height: 1.6;
		color: var(--eco-fg);
	}

	&__eco-more {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		font-weight: 600;
		font-size: 13.5px;
		color: var(--eco-accent);
		text-decoration: none;

		svg {
			width: 14px;
			height: 14px;
			transition: transform .18s;
		}

		&:hover svg {
			transform: translate(2px, -2px);
		}
	}

	&__eco-item {
		display: flex;
		align-items: center;
		gap: 13px;
		padding: 13px 14px;
		border: 1px solid transparent;
		border-radius: 11px;
		color: inherit;
		text-decoration: none;
		transition: background .18s, border-color .18s;

		&:hover {
			background: rgba(255, 255, 255, .06);
			border-color: rgba(255, 255, 255, .12);
		}

		&:hover .pu__eco-out {
			color: var(--eco-accent);
			transform: translate(1px, -1px);
		}
	}

	&__eco-logo {
		display: grid;
		place-items: center;
		flex: none;
		width: 34px;
		height: 34px;
		border: 1px solid rgba(255, 255, 255, .12);
		border-radius: 9px;
		background: rgba(255, 255, 255, .06);
		color: var(--eco-accent);

		svg {
			width: 17px;
			height: 17px;
		}
	}

	&__eco-txt {
		flex: 1;
		min-width: 0;

		b {
			display: block;
			font-size: 13.5px;
			color: var(--eco-fg);
		}

		span {
			font-family: var(--mono);
			font-size: 10.5px;
			letter-spacing: .01em;
			color: var(--eco-dim);
		}
	}

	&__eco-out {
		flex: none;
		width: 14px;
		height: 14px;
		color: var(--eco-dim);
		transition: color .18s, transform .18s;
	}

	&__eco-cta {
		display: flex;
		justify-content: center;
		padding: clamp(8px, 1.5vw, 16px) var(--pu-pad) clamp(28px, 3.5vw, 44px);
	}

	&__cta-btn {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		padding: 12px 24px;
		border: none;
		border-radius: 10px;
		background: var(--brand);
		color: var(--ink);
		font: inherit;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		transition: background .18s;

		svg {
			width: 16px;
			height: 16px;
			transition: transform .18s;
		}

		&:hover {
			background: var(--brand-strong);
		}

		&:hover svg {
			transform: translateX(3px);
		}
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
			border-left: none;
			border-top: 1px solid var(--line-soft);
		}

		&__rail-row {
			flex-direction: column;
			align-items: stretch;
			gap: 14px;
		}

		&__conn {
			display: none;
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
