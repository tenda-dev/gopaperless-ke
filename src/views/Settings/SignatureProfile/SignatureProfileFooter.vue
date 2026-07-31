<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="gp-footer">
		<div class="gp-footer__status">
			<span class="gp-footer__state">
				<span
					class="gp-footer__dot"
					:class="{ 'gp-footer__dot--dirty': dirty, 'gp-footer__dot--pending': !dirty && canSave }" />
				{{ statusLabel }}
			</span>
			<div v-if="dirty && changes.length" class="gp-footer__chips">
				<span v-for="change in changes" :key="change" class="gp-footer__chip">
					{{ change }}
				</span>
			</div>
		</div>

		<div class="gp-footer__actions">
			<button
				type="button"
				class="gp-footer__discard"
				:disabled="!dirty || saving"
				@click="$emit('discard')">
				{{ t('libresign', 'Discard') }}
			</button>
			<button
				type="button"
				class="gp-footer__save"
				:disabled="!canSave || saving"
				@click="$emit('save')">
				{{ saving ? t('libresign', 'Saving…') : t('libresign', 'Save changes') }}
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'

defineOptions({
	name: 'SignatureProfileFooter',
})

const props = withDefaults(defineProps<{
	dirty: boolean
	canSave?: boolean
	changes?: string[]
	saving?: boolean
}>(), {
	canSave: false,
	changes: () => [],
	saving: false,
})

const statusLabel = computed(() => {
	if (props.dirty) {
		return t('libresign', 'Unsaved changes')
	}
	if (props.canSave) {
		return t('libresign', 'Not saved yet')
	}
	return t('libresign', 'All changes saved')
})

defineEmits<{
	(event: 'discard'): void
	(event: 'save'): void
}>()
</script>

<style lang="scss" scoped>
.gp-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	padding: 16px 4px 4px;

	&__status {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
	}

	&__state {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 14px;
		font-weight: 600;
		color: #1e293b;
	}

	&__dot {
		width: 9px;
		height: 9px;
		border-radius: 50%;
		background: #cbd5e1;

		&--dirty {
			background: #2563eb;
		}

		&--pending {
			background: #f59e0b;
		}
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__chip {
		font-size: 12px;
		font-weight: 500;
		color: #475569;
		background: #f1f5f9;
		border: 1px solid #e2e8f0;
		border-radius: 8px;
		padding: 3px 9px;
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	&__discard {
		padding: 8px 18px;
		border: 1px solid #d7dce3;
		border-radius: 11px;
		background: #fff;
		color: #1e293b;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		transition: background 160ms ease;

		&:hover:not(:disabled) {
			background: #f5f6f8;
		}

		&:disabled {
			opacity: 0.45;
			cursor: not-allowed;
		}
	}

	&__save {
		padding: 8px 20px;
		border: none;
		border-radius: 11px;
		background-color: #04d56d;
		color: #073d1f;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		transition:
			background 160ms ease,
			transform 160ms ease,
			box-shadow 160ms ease;

		&:hover:not(:disabled) {
			background-color: #05e87a;
			transform: translateY(-1px);
			box-shadow: 0 4px 14px rgba(4, 213, 109, 0.32);
		}

		&:active:not(:disabled) {
			transform: scale(0.98);
			box-shadow: none;
		}

		&:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}
	}
}
</style>
