<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="pu__hero">
		<div class="pu__inner pu__hero-grid">
			<div class="pu__intro">
				<div class="pu__kicker">
					Digital Signatures
				</div>
				<h1 class="pu__title">
					Get a document signed
				</h1>
				<p class="pu__lede">
					Upload your document, drag signature fields where they're needed, add your signers, and hit send. That's it — your document is on its way in under 2 minutes.
				</p>

				<div class="pu__drop">
					<div class="pu__drop-t">Start with your PDF</div>
					<div class="pu__drop-h">you'll sign in securely to continue</div>

					<button type="button" class="pu-btn pu-btn--primary" @click="$emit('getStarted')">
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
</template>

<script setup lang="ts">
import {
	mdiTrayArrowUp,
	mdiShieldCheckOutline,
} from '@mdi/js'

defineOptions({ name: 'PublicUploadHero' })

defineProps<{
	signed: boolean
}>()

defineEmits<{
	getStarted: []
}>()
</script>

<style scoped lang="scss">
.pu__hero {
	width: 100%;
}

.pu__hero-grid {
	display: flex;
	flex-wrap: wrap;
}

.pu__intro {
	flex: 1 1 380px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: clamp(24px, 3.5vw, 52px) var(--pu-pad);
}

.pu__kicker {
	margin-bottom: 18px;
	font-family: var(--mono);
	font-weight: 600;
	font-size: 11.5px;
	letter-spacing: .16em;
	text-transform: uppercase;
	color: var(--brand-strong);
}

.pu__title {
	margin-bottom: 14px;
	font-size: clamp(28px, 3.4vw, 40px);
	font-weight: 700;
	line-height: 1.06;
	letter-spacing: -.03em;
}

.pu__lede {
	margin-bottom: 28px;
	max-width: 40ch;
	font-size: 15.5px;
	line-height: 1.6;
	color: var(--slate);
}

.pu__drop {
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

.pu__drop-t {
	margin-bottom: 3px;
	font-weight: 600;
	font-size: 15px;
}

.pu__drop-h {
	margin-bottom: 16px;
	font-family: var(--mono);
	font-size: 11.5px;
	letter-spacing: .02em;
	color: var(--faint);
}

.pu__canvas {
	flex: 1 1 400px;
	display: grid;
	place-items: center;
	min-height: 440px;
	padding: clamp(28px, 4vw, 52px);
	border-inline-start: 1px solid var(--line-soft);
	background: radial-gradient(120% 120% at 70% 10%, #fff 0%, var(--canvas) 60%);
}

.pu__stack {
	position: relative;
	width: min(360px, 82%);
}

.pu__paper-back {
	position: absolute;
	inset: 14px -12px -12px 14px;
	border: 1px solid var(--line);
	border-radius: 10px;
	background: #fff;
	opacity: .6;
}

.pu__paper {
	position: relative;
	padding: 26px 24px 22px;
	border: 1px solid var(--line);
	border-radius: 11px;
	background: #fff;
	box-shadow: 0 26px 60px -34px rgba(14, 17, 22, .4);
}

.pu__doc-meta {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.pu__doc-name {
	display: flex;
	align-items: center;
	gap: 9px;
}

.pu__doc-badge {
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

.pu__doc-t {
	font-weight: 600;
	font-size: 13px;
}

.pu__doc-p {
	margin-top: 2px;
	font-family: var(--mono);
	font-size: 10.5px;
	color: var(--faint);
}

.pu__doc-status {
	font-family: var(--mono);
	font-size: 10px;
	font-weight: 600;
	letter-spacing: .04em;
	color: var(--faint);
}

.pu__lines {
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

.pu__field {
	position: relative;
	padding: 12px 14px;
	border: 1.5px dashed color-mix(in srgb, var(--brand) 55%, transparent);
	border-radius: 9px;
	background: var(--brand-wash);
	transition: background .4s, border-color .4s;
}

.pu__field-label {
	position: absolute;
	top: -8px;
	inset-inline-start: 12px;
	padding: 0 6px;
	background: #fff;
	font-family: var(--mono);
	font-size: 9px;
	font-weight: 600;
	letter-spacing: .1em;
	text-transform: uppercase;
	color: var(--brand-strong);
}

.pu__field-hint {
	font-family: var(--mono);
	font-size: 11px;
	color: var(--brand-strong);
	opacity: .75;
	transition: opacity .3s;
}

.pu__sig {
	position: absolute;
	inset-inline-start: 16px;
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

.pu__signed-tag {
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

.pu__paper.is-signed {
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
</style>
