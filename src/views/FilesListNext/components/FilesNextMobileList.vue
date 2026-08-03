<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Mobile presentation of the Files list: card rows with a long-press
  - multi-select gesture, inline Sign shortcut and a per-row kebab.
  - Presentation only — rows, selection and select mode arrive as props and
  - every interaction (tap, long-press lifecycle, sign, kebab) is emitted.
-->
<template>
	<ul class="files-next__mlist">
		<li v-for="row in displayRows" :key="row.key" :data-row-id="row.id" class="m-row"
			:class="{ 'm-row--selected': selectedIds.has(row.id) }" @click="$emit('row-tap', row)"
			@contextmenu.prevent @pointerdown="$emit('press-start', $event, row)" @pointermove="$emit('press-move', $event)"
			@pointerup="$emit('press-end')" @pointercancel="$emit('press-end')" @pointerleave="$emit('press-end')">
			<span v-if="selectMode" class="m-check" :class="{ 'm-check--on': selectedIds.has(row.id) }"
				aria-hidden="true">
				<svg v-if="selectedIds.has(row.id)" class="icon icon--sm" viewBox="0 0 24 24">
					<path :d="mdiCheck" />
				</svg>
			</span>

			<!-- File-type thumbnail with extension badge (enlarged on mobile) -->
			<span class="files-next__filecard">
				<span class="files-next__filecard-ext" :style="{ color: badgeColor(row.ext, row.statusCode) }">{{ (row.ext ||
					'file').toUpperCase() }}</span>
				<span class="files-next__filecard-lines"><i /><i /><i /></span>
			</span>

			<span class="m-main">
				<span class="m-name">{{ row.name }}</span>
				<span v-if="row.requester" class="m-req"
					:class="{ 'm-req--email': isEmail(row.requester) }">
					<svg v-if="isEmail(row.requester)" class="icon icon--sm" viewBox="0 0 24 24"
						aria-hidden="true">
						<path :d="mdiEmailOutline" />
					</svg>
					{{ row.requester }}
				</span>
				<span class="m-meta">
					<span v-if="row.statusLabel && row.statusLabel !== 'none'" class="files-next__pill"
						:class="'files-next__pill--' + statusVariant(row.statusCode)">
						<span class="files-next__dot" />
						{{ row.statusLabel }}
					</span>
					<span class="m-time">{{ relativeTime(row.created) }}</span>
				</span>
			</span>

			<span v-if="!selectMode" class="m-actions">
				<button v-if="row.canSign" type="button" class="m-sign" @click.stop="$emit('sign', row)">{{
					t('libresign', 'Sign')
					}}</button>
				<button type="button" class="m-kebab"
					:aria-label="t('libresign', 'Actions for {name}', { name: row.name })"
					aria-haspopup="menu" @click.stop="$emit('kebab', $event, row)">
					<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
						<path :d="mdiDotsVertical" />
					</svg>
				</button>
			</span>
		</li>
	</ul>
</template>

<script setup lang="ts">
import { mdiCheck, mdiDotsVertical, mdiEmailOutline } from '@mdi/js'

import { t } from '@nextcloud/l10n'

import type { FilesNextRow } from '../useFilesNextRows.ts'
import { badgeColor, isEmail, relativeTime, statusVariant } from '../composables/rowFormatters.ts'

defineOptions({ name: 'FilesNextMobileList' })

defineProps<{
	displayRows: FilesNextRow[]
	selectedIds: Set<number | string>
	selectMode: boolean
}>()

defineEmits<{
	(e: 'row-tap', row: FilesNextRow): void
	(e: 'press-start', event: PointerEvent, row: FilesNextRow): void
	(e: 'press-move', event: PointerEvent): void
	(e: 'press-end'): void
	(e: 'kebab', event: MouseEvent, row: FilesNextRow): void
	(e: 'sign', row: FilesNextRow): void
}>()
</script>

<style scoped lang="scss">
.icon {
	width: 20px;
	height: 20px;
	fill: currentColor;
	flex: 0 0 auto;

	&--sm {
		width: 15px;
		height: 15px;
	}
}

// Mobile card list
.files-next__mlist {
	list-style: none;
	margin: 0;
	padding: 0;
	animation: files-next-fadein 0.35s ease both; // real rows fade in after skeleton
}

.m-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 4px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	user-select: none;
	-webkit-tap-highlight-color: transparent;

	&--selected {
		background: var(--color-primary-element-light, var(--color-background-dark));
	}
}

.m-check {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 24px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid var(--color-border-maxcontrast, #999);
	color: var(--color-primary-element-text, #fff);

	&--on {
		background: #04d56d;
		border-color: #04d56d;
	}
}

// File-type card thumbnail — enlarged on mobile (matches the screenshots)
.files-next__filecard {
	position: relative;
	display: flex;
	flex-direction: column;
	flex: 0 0 48px;
	width: 48px;
	height: 60px;
	padding: 7px 6px;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	margin-inline-end: 0;

	&-ext {
		font-size: 9px;
		font-weight: 800;
		line-height: 1;
		letter-spacing: 0.02em;
	}

	&-lines {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin-top: 8px;

		i {
			height: 3px;
			border-radius: 2px;
			background: var(--color-border);
		}

		i:nth-child(1) {
			width: 100%;
		}

		i:nth-child(2) {
			width: 80%;
		}

		i:nth-child(3) {
			width: 90%;
		}
	}
}

.m-main {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
	flex: 1 1 auto;
}

.m-name {
	font-size: 16px;
	font-weight: 500;
	color: var(--color-main-text);
	line-height: 1.25;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	// Break long unbroken filenames (e.g. underscore_pdf_names) so they fill
	// the 2-line clamp instead of truncating on a single line.
	overflow-wrap: anywhere;
	word-break: break-word;
}

.m-req {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	max-width: 100%;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;

	.icon {
		opacity: 0.7;
	}
}

.m-meta {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
}

.m-time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.m-actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.m-sign {
	height: 34px;
	padding: 0 14px;
	border: none;
	border-radius: 17px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}

.m-kebab {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	.icon {
		width: 22px;
		height: 22px;
		fill: currentColor;
	}

	&:hover {
		background: var(--color-background-dark);
	}
}

// Status pill
.files-next__pill {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	max-width: 100%;
	padding: 4px 11px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 500;
	line-height: 1.3;
	white-space: nowrap;
}

.files-next__dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex: 0 0 auto;
	background: currentColor;
}

.files-next__pill--draft {
	background: #f0f0f0;
	color: #616161;
}

.files-next__pill--ready {
	background: #fff6e0;
	color: #9a7500;
}

.files-next__pill--partial {
	background: #ecedf9;
	color: #3f51b5;
}

.files-next__pill--signed {
	background: #e6f4ea;
	color: #1e7d32;
}

.files-next__pill--neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

@keyframes files-next-fadein {
	from {
		opacity: 0;
		transform: translateY(4px);
	}

	to {
		opacity: 1;
		transform: translateY(0);
	}
}
</style>
