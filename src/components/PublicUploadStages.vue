<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pu__rail">
		<div class="pu__inner pu__rail-row">
			<template v-for="(stage, i) in stages" :key="stage.name">
				<div class="pu__stage">
					<div class="pu__stage-ic">
						<svg viewBox="0 0 24 24"><path :d="stage.icon" /></svg>
						<span class="pu__stage-n" aria-hidden="true">{{ i + 1 }}</span>
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
</template>

<script setup lang="ts">
import {
	mdiTrayArrowUp,
	mdiPlusBoxOutline,
	mdiAccountMultiplePlusOutline,
	mdiSendOutline,
} from '@mdi/js'

defineOptions({ name: 'PublicUploadStages' })

const stages = [
	{ name: 'Upload', sub: 'PDF · pages detected', icon: mdiTrayArrowUp },
	{ name: 'Add signers', sub: 'who signs, in order', icon: mdiAccountMultiplePlusOutline },
	{ name: 'Place signatures', sub: 'where each signer signs', icon: mdiPlusBoxOutline },
	{ name: 'Send', sub: 'request goes out', icon: mdiSendOutline },
]
</script>

<style scoped lang="scss">
.pu__rail {
	width: 100%;
	border-top: 1px solid var(--line-soft);
}

.pu__rail-row {
	display: flex;
	align-items: center;
	padding: clamp(16px, 2vw, 22px) var(--pu-pad);
}

.pu__stage {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: none;
	min-width: 0;
}

.pu__stage-ic {
	position: relative;
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

/* Step number — hidden on desktop (the linear row already reads as a sequence);
   shown on the mobile stepper where it makes the 1→4 order unmistakable. */
.pu__stage-n {
	display: none;
}

.pu__stage-tx {
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

.pu__conn {
	flex: 1;
	height: 1px;
	min-width: 16px;
	margin: 0 16px;
	background: var(--line);
}

/* Mobile — compact stepper: four nodes in a single row joined by one line,
   labels beneath, sublines dropped (desktop-only detail). ~92px vs the old
   ~210px stacked list, with nothing hidden. */
@media (max-width: 860px) {
	.pu__rail-row {
		position: relative;
		align-items: flex-start;
		gap: 4px;
		padding: 16px 4px 14px;
	}

	/* the connecting line, behind the dots (dots cover their own segment) */
	.pu__rail-row::before {
		content: "";
		position: absolute;
		top: 33px;
		left: 12.5%;
		right: 12.5%;
		height: 2px;
		background: var(--line);
		z-index: 0;
	}

	.pu__conn {
		display: none;
	}

	.pu__stage {
		position: relative;
		z-index: 1;
		flex: 1 1 0;
		flex-direction: column;
		align-items: center;
		gap: 7px;
		text-align: center;
	}

	.pu__stage-ic {
		width: 34px;
		height: 34px;
		border-radius: 50%;
		border: 1.5px solid var(--brand);
		background: var(--brand-wash);
		color: var(--brand-strong);

		svg {
			width: 17px;
			height: 17px;
		}
	}

	.pu__stage-n {
		position: absolute;
		top: -6px;
		right: -6px;
		display: grid;
		place-items: center;
		width: 16px;
		height: 16px;
		border-radius: 50%;
		border: 2px solid var(--surface);
		background: var(--ink);
		color: #fff;
		font-family: var(--mono);
		font-size: 9px;
		line-height: 1;
	}

	.pu__stage-tx {
		b {
			font-size: 10.5px;
			line-height: 1.25;
		}

		span {
			display: none;
		}
	}
}
</style>
