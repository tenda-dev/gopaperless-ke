<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	File-actions subheader.

	Surfaces the document/file actions (view / manage / validate) once the
	signers have finished — i.e. the file's own status is SIGNED. Without
	it, users have no obvious way to open the finished document. Keyed on
	the file status directly, not the workflow primaryAction.
-->
<template>
	<div v-if="isSigned" class="workflow-file-actions">
		<span class="workflow-file-actions__title">
			{{ t('libresign', 'Actions') }}
		</span>

		<div class="workflow-file-actions__rule" />

		<div class="workflow-file-actions__buttons">
			<NcButton
				v-if="isEnvelope"
				class="workflow-file-actions__button"
				variant="tertiary"
				size="small"
				wide
				@click="$emit('manage-files')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolder" :size="20" />
				</template>

				{{ t('libresign', 'Manage files ({count})', {
					count: file?.filesCount || 0,
				}) }}
			</NcButton>

			<NcButton
				v-else
				class="workflow-file-actions__button"
				variant="tertiary"
				size="small"
				wide
				@click="$emit('open-file')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" />
				</template>

				{{ t('libresign', 'View document') }}
			</NcButton>

			<NcButton
				v-if="canValidate"
				class="workflow-file-actions__button"
				variant="tertiary"
				size="small"
				wide
				@click="$emit('validate-file')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheckDecagramOutline" :size="20" />
				</template>

				{{ t('libresign', 'Validate') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@nextcloud/l10n'

import {
	mdiCheckDecagramOutline,
	mdiFileDocumentOutline,
	mdiFolder,
} from '@mdi/js'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import type { PublicFileState } from '../../../store/files'
import { FILE_STATUS } from '../../../constants'

defineOptions({
	name: 'WorkflowFileActions',
})

const props = defineProps<{
	file: PublicFileState | null
	isEnvelope: boolean
	canValidate: boolean
}>()

defineEmits<{
	(e: 'open-file'): void
	(e: 'manage-files'): void
	(e: 'validate-file'): void
}>()

// Only shown once the document is signed (all signers completed).
const isSigned = computed(() => props.file?.status === FILE_STATUS.SIGNED)
</script>

<style scoped lang="scss">
.workflow-file-actions {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.workflow-file-actions__title {
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

// Soft, greyed separator beneath the title.
.workflow-file-actions__rule {
	width: 100%;
	height: 1px;
	background: var(--color-border);
	opacity: 0.6;
}

.workflow-file-actions__buttons {
	display: flex;
	gap: 12px;

	:deep(.button-vue) {
		border: 1px solid var(--color-border);
	}
}

.workflow-file-actions__button {
	flex: 1;
}
</style>
