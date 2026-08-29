<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Floating desktop selection pill — overlays the top of the view above the
  - title and offers single-selection actions. Presentation only: it takes the
  - selection summary as props and emits intent events; the parent owns the
  - actual dispatch (so it stays read-only over the store).
-->
<template>
	<Transition name="fn-pill">
		<div v-if="show" class="fn-pill" role="region" :aria-label="t('libresign', 'Selection actions')">
			<span class="fn-pill__count">{{ n('libresign', '{count} selected', '{count} selected', count, { count }) }}</span>
			<span class="fn-pill__div" />
			<!-- Single-selection actions only; bulk stays in the row context menu for now -->
			<template v-if="hasSingle">
				<button type="button" class="fn-pill__btn" @click="$emit('view')">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiEyeOutline" /></svg>
					{{ t('libresign', 'View') }}
				</button>
				<button v-if="canDownload" type="button" class="fn-pill__btn" @click="$emit('download')">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiDownloadOutline" /></svg>
					{{ t('libresign', 'Download') }}
				</button>
				<button v-if="canDelete" type="button" class="fn-pill__btn fn-pill__btn--danger" @click="$emit('delete')">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiTrashCanOutline" /></svg>
					{{ t('libresign', 'Delete') }}
				</button>
			</template>
			<button type="button" class="fn-pill__btn fn-pill__btn--icon"
				:aria-label="t('libresign', 'Clear selection')" @click="$emit('clear')">
				<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiClose" /></svg>
			</button>
		</div>
	</Transition>
</template>

<script setup lang="ts">
import { mdiClose, mdiDownloadOutline, mdiEyeOutline, mdiTrashCanOutline } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'

defineOptions({ name: 'FilesNextSelectionPill' })

defineProps<{
	show: boolean
	count: number
	hasSingle: boolean
	canDownload: boolean
	canDelete: boolean
}>()

defineEmits<{
	(e: 'view'): void
	(e: 'download'): void
	(e: 'delete'): void
	(e: 'clear'): void
}>()
</script>

<style scoped lang="scss">
.fn-pill {
	// Floating overlay at the very top of the view, above the title.
	position: absolute;
	top: 10px;
	left: 50%;
	transform: translateX(-50%);
	z-index: 50;
	display: flex;
	align-items: center;
	gap: 2px;
	width: fit-content;
	max-width: calc(100% - 24px);
	padding: 4px 8px;
	border-radius: 10px;
	background: #1c1c1e;
	color: #fff;
	box-shadow: 0 12px 34px rgba(0, 0, 0, 0.30);

	.icon { width: 18px; height: 18px; fill: currentColor; }

	&__count {
		font-size: 13px;
		font-weight: 400;
		padding: 0 10px 0 12px;
		white-space: nowrap;
	}

	&__div {
		width: 1px;
		height: 22px;
		margin: 0 4px;
		background: rgba(255, 255, 255, 0.18);
	}

	&__btn {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		height: 38px;
		padding: 0 15px;
		border: none;
		border-radius: 10px;
		background: transparent;
		color: #fff;
		font-size: 13px;
		font-weight: 400;
		cursor: pointer;
		white-space: nowrap;

		// Kill the lingering focus/active background left after a mouse click
		// (Nextcloud's global button styling). Keyboard focus keeps a ring.
		&:focus,
		&:active,
		&:focus-visible {
			background: transparent;
			outline: none;
		}
		&:hover { background: rgba(255, 255, 255, 0.12); }
		&:focus-visible { box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.55); }

		&--danger { color: #ff6b6b; }
		&--icon { width: 38px; padding: 0; justify-content: center; }
	}
}

// Slide-in/out from the top of the view (keeps the pill centered).
.fn-pill-enter-active,
.fn-pill-leave-active {
	transition: opacity 0.22s ease, transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}
.fn-pill-enter-from,
.fn-pill-leave-to {
	opacity: 0;
	transform: translate(-50%, -160%);
}
</style>
