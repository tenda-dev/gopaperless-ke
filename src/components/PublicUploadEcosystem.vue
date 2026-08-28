<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu__eco">
		<div class="pu__inner pu__eco-cols">
			<div class="pu__eco-about">
				<div class="pu__eco-kicker">About</div>
				<p class="pu__eco-lede">
					GoPaperless™ is the easiest way to sign documents online, built for Africa. Send, sign, and manage legally binding documents in minutes.
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

		<div class="pu__inner pu__eco-legal">
			<a class="pu__eco-legal-link" href="https://tendaworld.com/gopaperless/" target="_blank" rel="noopener">Terms and Conditions</a>
			<span class="pu__eco-legal-sep" aria-hidden="true">·</span>
			<a class="pu__eco-legal-link" href="https://tendaworld.com/gopaperless/" target="_blank" rel="noopener">Privacy Policy</a>
		</div>
	</div>
</template>

<script setup lang="ts">
import {
	mdiShieldCheckOutline,
	mdiShieldLockOutline,
	mdiArrowTopRight,
} from '@mdi/js'

defineOptions({ name: 'PublicUploadEcosystem' })

const ecosystem = [
	{ name: 'JuliCA Certification Authority', sub: 'Issued and Regulated by the Communications Authority of Kenya', href: 'https://tendaworld.com/julica', icon: mdiShieldCheckOutline },
	{ name: 'Securysign', sub: 'The public key infrastructure built for Africa', href: 'https://tendaworld.com/securysign', icon: mdiShieldLockOutline },
]
</script>

<style scoped lang="scss">
.pu__eco {
	width: 100%;
	background: var(--eco-bg);
}

.pu__eco-cols {
	display: flex;
	flex-wrap: wrap;
	gap: clamp(24px, 4vw, 56px);
	padding: clamp(20px, 2.6vw, 30px) var(--pu-pad) clamp(16px, 2vw, 22px);
}

.pu__eco-about {
	flex: 1 1 320px;
}

.pu__eco-platform {
	flex: 1 1 300px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.pu__eco-kicker {
	margin-bottom: 14px;
	font-family: var(--mono);
	font-weight: 600;
	font-size: 10.5px;
	letter-spacing: .14em;
	text-transform: uppercase;
	color: var(--eco-dim);
}

.pu__eco-lede {
	margin-bottom: 16px;
	max-width: 46ch;
	font-size: 14px;
	line-height: 1.6;
	color: var(--eco-fg);
}

.pu__eco-more {
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

.pu__eco-item {
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

.pu__eco-logo {
	display: grid;
	place-items: center;
	flex: none;
	width: 34px;
	height: 34px;
	border: 1px solid rgba(255, 255, 255, .12);
	border-radius: 9px;
	background: rgba(255, 255, 255, .06);
	// White/light icon for contrast against the dark footer.
	color: #ffffff;

	svg {
		width: 17px;
		height: 17px;
	}
}

.pu__eco-txt {
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

.pu__eco-out {
	flex: none;
	width: 14px;
	height: 14px;
	color: var(--eco-dim);
	transition: color .18s, transform .18s;
}

.pu__eco-legal {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 10px;
	padding: clamp(8px, 1.4vw, 14px) var(--pu-pad) clamp(16px, 2vw, 24px);
}

.pu__eco-legal-link {
	font-size: 12.5px;
	color: var(--eco-dim);
	text-decoration: none;
	transition: color .18s;

	&:hover,
	&:focus-visible {
		color: var(--eco-accent);
		text-decoration: underline;
	}
}

.pu__eco-legal-sep {
	color: rgba(255, 255, 255, .28);
	font-size: 12px;
}
</style>
