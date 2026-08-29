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
	padding: clamp(22px, 3vw, 32px) var(--pu-pad);
}

.pu__stage {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: none;
	min-width: 0;
}

.pu__stage-ic {
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

@media (max-width: 860px) {
	.pu__rail-row {
		flex-direction: column;
		align-items: stretch;
		gap: 14px;
	}

	.pu__conn {
		display: none;
	}
}
</style>
