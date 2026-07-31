<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Confirmation / rename dialog for the Files list. Presentation only: the
  - parent owns the dialog state and the action callbacks (passed via `buttons`);
  - this component renders the NcDialog and the kind-specific body.
-->
<template>
	<NcDialog :open="open" :name="name" :buttons="buttons" size="small"
		@update:open="(v: boolean) => $emit('update:open', v)">
		<p v-if="message" class="fn-dialog__msg">{{ message }}</p>
		<div v-if="kind === 'rename'" class="fn-dialog__field">
			<input ref="renameInput" :value="renameValue" type="text" class="fn-dialog__input"
				:placeholder="t('libresign', 'Document name')"
				@input="$emit('update:renameValue', ($event.target as HTMLInputElement).value)"
				@keydown.enter.prevent="$emit('submit')">
		</div>
		<NcCheckboxRadioSwitch v-if="kind === 'delete' || kind === 'bulkDelete'" type="switch"
			:model-value="deleteFileToo" @update:model-value="(v: boolean) => $emit('update:deleteFileToo', v)">
			{{ t('libresign', 'Also delete the file.') }}
		</NcCheckboxRadioSwitch>
	</NcDialog>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineOptions({ name: 'FilesNextConfirmDialog' })

/** Matches the NcDialog button shape the parent builds. */
type DialogButton = { label: string, callback: () => void, variant?: 'primary' | 'error' }

const props = defineProps<{
	open: boolean
	name: string
	message: string
	kind: 'delete' | 'bulkDelete' | 'rename' | null
	buttons: DialogButton[]
	renameValue: string
	deleteFileToo: boolean
}>()

defineEmits<{
	(e: 'update:open', v: boolean): void
	(e: 'update:renameValue', v: string): void
	(e: 'update:deleteFileToo', v: boolean): void
	(e: 'submit'): void
}>()

const renameInput = ref<HTMLInputElement | null>(null)

// Focus the field when the rename dialog opens.
watch(() => props.open && props.kind === 'rename', (renaming) => {
	if (renaming) {
		nextTick(() => renameInput.value?.focus())
	}
})
</script>

<style scoped lang="scss">
.fn-dialog__msg { margin: 0 0 4px; }
.fn-dialog__field { padding: 8px 0 4px; }
.fn-dialog__input {
	width: 100%;
	height: 40px;
	padding: 0 12px;
	box-sizing: border-box;
	border: 2px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 15px;

	&:focus {
		border-color: var(--color-primary-element);
		outline: none;
	}
}
</style>
