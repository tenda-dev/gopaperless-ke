<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Conjoined document-stage control for PublicWorkflowCurrentShell — the "current"
  restyle's single pill: a `sample ▾` dropdown (quick-glance Pages/Status/
  Expires/Privacy) plus Details/Document tabs. Presentation-only composition of
  two existing leaves — no store coupling of its own:
    - Details (default) → PwDocumentCard (thumbnail + name + pages·date·signers).
    - Document          → PwDocumentStage, embedded with show-bar="false" so its
                           own doc-chip isn't duplicated; this owns the one pill.
  Before any document exists, PwDocumentStage's own "unavailable" state (no pill)
  is shown as-is — matches the mockup's empty stage.
-->
<template>
	<div class="pw-stagectl">
		<div v-if="hasDocument && stageReady" class="pw-stagectl__bar">
			<div ref="metaWrap" class="pw-stagectl__dd">
				<button type="button" class="pw-stagectl__trigger" :class="{ 'is-open': metaOpen }"
					:aria-expanded="metaOpen" @click="metaOpen = !metaOpen">
					<NcIconSvgWrapper class="pw-stagectl__trigger-ic" :path="mdiFileDocumentOutline" :size="15" />
					<span class="pw-stagectl__trigger-name">{{ name || t('libresign', 'Your document') }}</span>
					<NcIconSvgWrapper class="pw-stagectl__trigger-chev" :class="{ 'is-open': metaOpen }"
						:path="mdiChevronDown" :size="15" />
				</button>
				<transition name="pw-stagectl-meta">
					<div v-if="metaOpen" class="pw-stagectl__meta" role="region"
						:aria-label="t('libresign', 'Document details')">
						<dl class="pw-stagectl__meta-list">
							<div class="pw-stagectl__meta-row">
								<dt>{{ t('libresign', 'Pages') }}</dt>
								<dd>{{ pages > 0 ? pages : '—' }}</dd>
							</div>
							<div class="pw-stagectl__meta-row">
								<dt>{{ t('libresign', 'Status') }}</dt>
								<dd><span class="pw-stagectl__badge">
										<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="12" /> {{ t('libresign',
										'Draft') }}
									</span></dd>
							</div>
							<div v-if="expiry" class="pw-stagectl__meta-row">
								<dt>{{ t('libresign', 'Expires') }}</dt>
								<dd>{{ expiry }}</dd>
							</div>
							<div class="pw-stagectl__meta-row">
								<dt>{{ t('libresign', 'Privacy') }}</dt>
								<dd>{{ t('libresign', 'Kept private · nothing sent') }}</dd>
							</div>
						</dl>
					</div>
				</transition>
			</div>
			<button type="button" class="pw-stagectl__tab" :aria-pressed="tab === 'details'" @click="tab = 'details'">
				{{ t('libresign', 'Details') }}
			</button>
			<button type="button" class="pw-stagectl__tab" :aria-pressed="tab === 'document'" @click="tab = 'document'">
				{{ t('libresign', 'Document') }}
			</button>
		</div>

		<div v-show="hasDocument && stageReady && tab === 'details'" class="pw-stagectl__card">
			<PwDocumentCard :name="name" :pages="pages" :expiry="expiry" :signer-count="signerCount"
				:status-label="statusLabel" :status-variant="statusVariant" @view="tab = 'document'" />
		</div>
		<div v-show="!(hasDocument && stageReady && tab === 'details')" class="pw-stagectl__pdf">
			<PwDocumentStage :document="document" :pages="pages" :expiry="expiry" :active="active" :show-bar="false"
				@status-change="onStatusChange" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { mdiChevronDown, mdiFileDocumentOutline, mdiShieldCheckOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import PwDocumentCard from './PwDocumentCard.vue'
import PwDocumentStage from './PwDocumentStage.vue'
import type { WorkflowDocument } from '../../services/publicWorkflow'

defineOptions({ name: 'PwStageControl' })

withDefaults(defineProps<{
	document: WorkflowDocument | null
	hasDocument?: boolean
	active?: boolean
	name?: string
	pages?: number
	expiry?: string
	signerCount?: number
	statusLabel?: string
	statusVariant?: 'draft' | 'purple' | 'ready'
}>(), {
	hasDocument: false,
	active: true,
	name: '',
	pages: 0,
	expiry: '',
	signerCount: 0,
	statusLabel: '',
	statusVariant: 'draft',
})

const tab = ref<'details' | 'document'>('details')
const metaOpen = ref(false)
// Mirrors PwDocumentStage's internal fetch status: the pill only appears once the
// PDF render pipeline is actually ready, so it can't flash in ahead of the canvas.
const stageReady = ref(false)
function onStatusChange(status: 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'): void {
	stageReady.value = status === 'ready'
	if (status !== 'ready') { metaOpen.value = false }
}
</script>

<style scoped lang="scss">
@use '../AdminGrants/tokens.scss' as agtokens;

.pw-stagectl {
	@include agtokens.age-tokens;
	display: flex;
	flex-direction: column;
	min-height: 0;
	height: 100%;

	&__bar {
		flex: none;
		display: flex;
		justify-content: center;
	}

	&__dd {
		position: relative;
		display: flex;
	}

	&__trigger,
	&__tab {
		font: inherit;
		font-size: 12.5px;
		font-weight: 600;
		cursor: pointer;
	}

	&__dd,
	&__tab {
		background: transparent;
	}

	// Conjoined pill: trigger + tabs share ONE outline (on &__bar below); __dd and
	// __tab must NOT carry their own borders or they render as separate boxed
	// segments instead of one seamless pill (the bug the first pass shipped with —
	// a `> .pw-stagectl__dd, > .pw-stagectl__tab { border: 1px solid }` rule here
	// out-specificity'd the single-class overrides further down).
	&__bar {
		border: 1px solid var(--age-border, var(--color-border));
		border-radius: 999px;
		background: var(--age-surface, var(--color-main-background));
		box-shadow: 0 1px 2px rgba(13, 17, 22, 0.05);
		display: inline-flex;
		width: max-content;
		margin-inline: auto;
	}

	&__dd {
		border: none;
	}

	&__trigger {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		border: none;
		background: transparent;
		color: var(--age-fg, var(--color-main-text));
		border-radius: 999px 0 0 999px;
		padding: 8px 14px 8px 15px;
		max-width: min(50vw, 260px);

		&:hover {
			background: var(--age-surface-hover, var(--color-background-hover));
		}

		&.is-open {
			background: var(--age-surface-hover, var(--color-background-hover));
		}

		&:focus-visible {
			outline: 2px solid var(--age-focus, var(--color-primary-element));
			outline-offset: 2px;
		}
	}

	&__trigger-ic {
		flex: none;
		color: var(--age-success-fg, #0a6636);
	}

	&__trigger-name {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__trigger-chev {
		flex: none;
		color: var(--age-fg-faint, var(--color-text-maxcontrast));
		transition: transform 0.18s;

		&.is-open {
			transform: rotate(180deg);
		}
	}

	&__tab {
		border: none;
		border-left: 1px solid var(--age-border, var(--color-border));
		background: transparent;
		color: var(--age-fg-muted, var(--color-text-maxcontrast));
		padding: 8px 16px;

		&:last-child {
			border-radius: 0 999px 999px 0;
		}

		&[aria-pressed="true"] {
			color: var(--age-fg, var(--color-main-text));
			background: var(--age-surface-hover, var(--color-background-hover));
		}

		&:focus-visible {
			outline: 2px solid var(--age-focus, var(--color-primary-element));
			outline-offset: 2px;
		}
	}

	&__meta {
		position: absolute;
		top: calc(100% + 8px);
		inset-inline-start: 0;
		z-index: 20;
		width: max-content;
		max-width: min(86vw, 320px);
		background: var(--age-surface, var(--color-main-background));
		border: 1px solid var(--age-border, var(--color-border));
		border-radius: var(--age-radius, 12px);
		box-shadow: 0 2px 6px rgba(13, 17, 22, 0.06), 0 24px 48px -24px rgba(13, 17, 22, 0.4);
		padding: 10px 14px;
	}

	&__meta-list {
		margin: 0;
		display: flex;
		flex-direction: column;
	}

	&__meta-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 18px;
		padding: 7px 0;
		font-size: 12.5px;

		&+& {
			border-top: 1px solid var(--age-border, var(--color-border));
		}

		dt {
			color: var(--age-fg-muted, var(--color-text-maxcontrast));
			margin: 0;
		}

		dd {
			margin: 0;
			color: var(--age-fg, var(--color-main-text));
			font-weight: 600;
			text-align: end;
		}
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		font-family: var(--tenda-font-mono, var(--mono, monospace));
		font-size: 10px;
		font-weight: 600;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--age-success-fg, #0a6636);
		padding: 3px 8px;
		border-radius: 999px;
		background: var(--age-success-tint, rgba(4, 213, 109, 0.12));
		border: 1px solid var(--age-success-border, rgba(4, 213, 109, 0.34));

		:deep(span.icon-vue) {
			color: var(--age-success-fg, #0a6636);
		}
	}

	&__card {
		flex: none;
		width: 100%;
		max-width: 560px;
		margin: 16px auto 0;
		padding: 0 24px;
	}

	&__pdf {
		flex: 1 1 auto;
		min-height: 0;
	}
}

.pw-stagectl-meta-enter-active,
.pw-stagectl-meta-leave-active {
	transition: opacity 0.16s ease, transform 0.16s ease;
}

.pw-stagectl-meta-enter-from,
.pw-stagectl-meta-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}
</style>
