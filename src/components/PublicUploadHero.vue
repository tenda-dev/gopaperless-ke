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
					Simplify your paperwork
				</h1>
				<p class="pu__lede pu__lede--full">
					Upload your document, add your signers, place the signature fields, and send it — the whole process takes just a couple of minutes.
				</p>
				<p class="pu__lede pu__lede--short">
					The whole process takes just a couple of minutes.
				</p>
			</div>

			<!-- Real upload entry point (replaces the former decorative illustration).
			     Horizontal card: big icon tile · label · action affordance — one
			     button, collapses to a compact bar on mobile. -->
			<div class="pu__canvas">
				<button
					type="button"
					class="pu__drop"
					aria-label="Upload document"
					@click="$emit('getStarted')">
					<span class="pu__drop-tile">
						<svg viewBox="0 0 24 24"><path :d="mdiTrayArrowUp" /></svg>
					</span>
					<span class="pu__drop-body">
						<span class="pu__drop-t">Upload your document</span>
						<span class="pu__drop-h">PDF · you'll sign in securely to continue</span>
					</span>
					<span class="pu__drop-go" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path :d="mdiChevronRight" /></svg>
					</span>
				</button>
			</div>
		</div>
	</section>
</template>

<script setup lang="ts">
import { mdiTrayArrowUp, mdiChevronRight } from '@mdi/js'

defineOptions({ name: 'PublicUploadHero' })

defineEmits<{
	getStarted: []
}>()
</script>

<style scoped lang="scss">
.pu__hero {
	width: 100%;
	flex: 1 1 auto;
	display: flex;
	min-height: 240px;
}

.pu__hero-grid {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	align-items: stretch;
}

.pu__intro {
	flex: 1 1 380px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: clamp(20px, 2.4vw, 34px) var(--pu-pad);
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
	margin-bottom: 0;
	max-width: 40ch;
	font-size: 15.5px;
	line-height: 1.6;
	color: var(--slate);
}

/* On mobile the full lede is redundant with the rail right below the card,
   so it collapses to just the closing line. */
.pu__lede--short {
	display: none;
}

.pu__canvas {
	flex: 1 1 400px;
	display: grid;
	place-items: center;
	min-height: 0;
	padding: clamp(20px, 2.6vw, 34px);
	border-inline-start: 1px solid var(--line-soft);
	background: radial-gradient(120% 120% at 70% 10%, #fff 0%, var(--canvas) 60%);
}

/* Upload card — horizontal: [ icon tile ] [ label ] [ go ]. The whole card is
   one button; the go chip echoes the brand action without a second tab stop. */
.pu__drop {
	display: flex;
	align-items: center;
	gap: 16px;
	width: min(420px, 100%);
	padding: 16px;
	text-align: start;
	font: inherit;
	color: inherit;
	cursor: pointer;
	border: 1.5px dashed color-mix(in srgb, var(--brand) 40%, transparent);
	border-radius: 16px;
	background: var(--brand-wash);
	transition: border-color .2s, box-shadow .2s, transform .12s;

	&:hover {
		border-color: var(--brand);
		box-shadow: 0 0 0 4px var(--brand-ring);
	}

	&:hover .pu__drop-go {
		background: var(--brand-strong);
	}

	&:active {
		transform: translateY(1px);
	}

	&:focus-visible {
		outline: 2px solid var(--brand-strong);
		outline-offset: 2px;
	}
}

.pu__drop-tile {
	flex: none;
	display: grid;
	place-items: center;
	width: 84px;
	height: 84px;
	border-radius: 16px;
	background: var(--brand-tint);
	color: var(--ink);

	svg {
		width: 44px;
		height: 44px;
		fill: currentColor;
	}
}

.pu__drop-body {
	flex: 1;
	min-width: 0;
}

.pu__drop-t {
	display: block;
	font-weight: 600;
	font-size: 16px;
	letter-spacing: -.01em;
}

.pu__drop-h {
	display: block;
	margin-top: 3px;
	font-family: var(--mono);
	font-size: 11.5px;
	letter-spacing: .01em;
	color: var(--faint);
}

.pu__drop-go {
	flex: none;
	display: grid;
	place-items: center;
	width: 40px;
	height: 40px;
	border-radius: 10px;
	background: var(--brand);
	color: var(--ink);
	transition: background .2s;

	svg {
		width: 20px;
		height: 20px;
		fill: currentColor;
	}
}

/* Mobile — stack the hero; the upload card becomes a compact bar. These rules
   live HERE (not in PublicUpload.vue) because scoped CSS can't reach a child
   component's inner elements from the parent. */
@media (max-width: 860px) {
	.pu__hero {
		flex: 0 0 auto;
		min-height: 0;
	}

	.pu__lede--full {
		display: none;
	}

	.pu__lede--short {
		display: block;
	}

	.pu__hero-grid {
		flex-direction: column;
	}

	.pu__intro {
		flex: 0 0 auto;
	}

	.pu__canvas {
		order: 2;
		flex: 0 0 auto;
		min-height: 0;
		padding: 0 var(--pu-pad) clamp(20px, 5vw, 28px);
		border-inline-start: none;
		background: none;
	}

	.pu__drop {
		width: 100%;
		gap: 13px;
		padding: 12px;
	}

	.pu__drop-tile {
		width: 56px;
		height: 56px;
		border-radius: 12px;

		svg {
			width: 30px;
			height: 30px;
		}
	}

	.pu__drop-t {
		font-size: 14.5px;
	}

	.pu__drop-h {
		font-size: 11px;
	}

	.pu__drop-go {
		width: 36px;
		height: 36px;

		svg {
			width: 16px;
			height: 16px;
		}
	}
}
</style>
