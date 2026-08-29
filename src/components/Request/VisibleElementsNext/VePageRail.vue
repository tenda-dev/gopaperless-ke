<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav class="ve-rail" :aria-label="t('libresign', 'Document pages')">
		<span class="ve-rail__title">{{ t('libresign', 'Pages') }}</span>
		<div class="ve-rail__list">
			<button
				v-for="page in pages"
				:key="page.globalPage"
				type="button"
				class="ve-rail__page"
				:class="{ 've-rail__page--active': page.globalPage === currentGlobalPage }"
				:aria-current="page.globalPage === currentGlobalPage ? 'true' : undefined"
				:aria-label="pageAriaLabel(page)"
				@click="$emit('select-page', page.docIndex, page.localPageIndex, page.globalPage)">
				<span class="ve-rail__thumb" />
				<span class="ve-rail__dots" aria-hidden="true">
					<span
						v-for="signRequestId in shownDots(page.globalPage)"
						:key="signRequestId"
						class="ve-rail__dot"
						:style="{ backgroundColor: colorFor(signRequestId) }" />
					<span v-if="extraCount(page.globalPage) > 0" class="ve-rail__more">
						+{{ extraCount(page.globalPage) }}
					</span>
				</span>
				<span class="ve-rail__num">{{ page.globalPage }}</span>
			</button>
		</div>
	</nav>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

defineOptions({
	name: 'VePageRail',
})

export type RailPage = {
	globalPage: number
	docIndex: number
	/** Zero-based page index within its document. */
	localPageIndex: number
	fileName?: string
}

const MAX_DOTS = 4

const props = withDefaults(defineProps<{
	pages: RailPage[]
	signersByPage?: Record<number, number[]>
	currentGlobalPage?: number
	colorFor: (signRequestId: number) => string
}>(), {
	signersByPage: () => ({}),
	currentGlobalPage: 0,
})

defineEmits<{
	(event: 'select-page', docIndex: number, localPageIndex: number, globalPage: number): void
}>()

function signersOn(globalPage: number): number[] {
	return props.signersByPage[globalPage] || []
}

// Cap the dots so a page with many signers (e.g. 10) stays tidy: show the first
// few colours, then a "+N" pill for the rest.
function shownDots(globalPage: number): number[] {
	const list = signersOn(globalPage)
	return list.length > MAX_DOTS ? list.slice(0, MAX_DOTS - 1) : list
}

function extraCount(globalPage: number): number {
	const total = signersOn(globalPage).length
	return total > MAX_DOTS ? total - (MAX_DOTS - 1) : 0
}

function pageAriaLabel(page: RailPage): string {
	const count = signersOn(page.globalPage).length
	if (count > 0) {
		return t('libresign', 'Go to page {page} — {count} signature(s) here', { page: page.globalPage, count })
	}
	return t('libresign', 'Go to page {page} — no signatures', { page: page.globalPage })
}
</script>

<style lang="scss" scoped>
.ve-rail {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 8px;

	&__title {
		font-size: 12px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		padding: 0 2px;
		text-transform: uppercase;
		letter-spacing: 0.03em;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 14px;
		overflow-y: auto;

		@media (max-width: 767px) {
			flex-direction: row;
			overflow-x: auto;
			overflow-y: hidden;
		}
	}

	&__page {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 5px;
		flex-shrink: 0;
		padding: 2px;
		border: none;
		background: transparent;
		cursor: pointer;
	}

	&__thumb {
		width: 56px;
		height: 74px;
		border-radius: 5px;
		background-color: var(--color-main-background);
		border: 1.5px solid var(--color-border);
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
		transition: border-color 120ms ease, box-shadow 120ms ease;

		.ve-rail__page:hover & {
			border-color: var(--color-border-dark, var(--color-border));
		}

		.ve-rail__page--active & {
			border-color: #04d56d;
			box-shadow: 0 0 0 1px #04d56d;
		}
	}

	&__dots {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 3px;
		max-width: 56px;
		min-height: 8px;
	}

	&__dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		flex-shrink: 0;
	}

	&__more {
		font-size: 10px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		line-height: 1;
	}

	&__num {
		font-size: 11px;
		color: var(--color-text-maxcontrast);

		.ve-rail__page--active & {
			color: var(--color-main-text);
			font-weight: 600;
		}
	}
}
</style>
