<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<tr class="files-list__row"
		@contextmenu="onRightClick">
		<!-- Checkbox -->
		<FileEntryCheckbox :is-loading="filesStore.loading || renamingSaving"
			:source="source" />

		<td class="files-list__row-name"
		    @click="handleOpenDocument">
			<FileEntryPreview :source="source" />
			<FileEntryName ref="name"
				:basename="source.name"
				:extension="fileExtension"
				@rename="onRename"
				@renaming="onFileRenaming" />
		</td>

		<!-- Actions -->
		<FileEntryActions ref="actions"
			:class="`files-list__row-actions-${source.id}`"
			v-model:opened="openedMenu"
			:source="source"
			:loading="loading"
			@start-rename="onStartRename" />

		<!-- Status -->
		<td class="files-list__row-status"
			@click="openDetailsIfAvailable">
			<FileEntryStatus :status="source.status"
				:status-text="source.statusText"
				:signers="source.signers || []" />
		</td>

		<!-- Signers Count -->
		<td v-if="showSigners"
			class="files-list__row-signers"
			@click="openDetailsIfAvailable">
			<FileEntrySigners :signers-count="source.signersCount || 0"
				:signers="source.signers || []" />
		</td>

		<!-- Mtime -->
		<td :style="mtimeOpacity"
			class="files-list__row-mtime"
			@click="openDetailsIfAvailable">
			<NcDateTime v-if="source.created_at" :timestamp="mtime" :ignore-seconds="true" />
		</td>

		<!-- Open document -->
		<td class="files-list__row-open">
			<button type="button"
				class="files-list__open-btn"
				:aria-label="t('libresign', 'Open document')"
				@click.stop="handleOpenDocument">
				<span class="files-list__open-btn-label">{{ t('libresign', 'Open') }}</span>
			</button>
		</td>
	</tr>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'

import NcDateTime from '@nextcloud/vue/components/NcDateTime'

import FileEntryActions from './FileEntryActions.vue'
import FileEntryCheckbox from './FileEntryCheckbox.vue'
import FileEntryName from './FileEntryName.vue'
import FileEntryPreview from './FileEntryPreview.vue'
import FileEntrySigners from './FileEntrySigners.vue'
import FileEntryStatus from './FileEntryStatus.vue'

import { useFileEntry, type FileEntrySource } from '../../../composables/useFileEntry.js'
import { useActionsMenuStore } from '../../../store/actionsmenu.js'
import { useFilesStore } from '../../../store/files.js'
import { useUserConfigStore } from '../../../store/userconfig.js'
import { showInfo, showSuccess } from '../../../services/toast'
import { openDocument } from '../../../utils/viewer.js'
import { generateUrl } from '@nextcloud/router'

defineOptions({
	name: 'FileEntry',
})

type FileEntryActionsRef = {
	doRename: (newName: string) => Promise<void>
}

type FileEntryNameRef = {
	startRenaming?: () => void
	stopRenaming?: () => void
}

const props = defineProps<{
	source: FileEntrySource
	loading: boolean
}>()

const actionsMenuStore = useActionsMenuStore()
const filesStore = useFilesStore()
const userConfigStore = useUserConfigStore()
const { mtime, openedMenu, mtimeOpacity, fileExtension, onRightClick, openDetailsIfAvailable } = useFileEntry(props, {
	actionsMenuStore,
	filesStore,
})

// Admin toggle (default true) controls whether the Signers column shows.
const showSigners = computed(() => userConfigStore.files_list_show_signers !== false)

const file = computed(() => filesStore.files[props.source.id])
const actions = ref<FileEntryActionsRef | null>(null)
const name = ref<FileEntryNameRef | null>(null)
const isRenaming = ref(false)
const renamingSaving = ref(false)

async function onRename(newName: string) {
	const oldName = props.source.name
	renamingSaving.value = true
	try {
		await actions.value?.doRename(newName)
		name.value?.stopRenaming?.()
		showSuccess(t('libresign', 'Renamed "{oldName}" to "{newName}"', {
			oldName,
			newName,
		}))
	} finally {
		renamingSaving.value = false
	}
}

function onStartRename() {
	name.value?.startRenaming?.()
}

function onFileRenaming(nextIsRenaming: boolean) {
	isRenaming.value = nextIsRenaming
}

function handleOpenDocument() {
	if (!props?.source || !props?.source?.id) return

    if (props.source?.nodeType === 'envelope') {
		showInfo(t('libresign', 'Opening envelope files are not supported yet'))
		return
	}

	const fileUrl = props.source.file
		|| generateUrl('/apps/libresign/p/pdf/{uuid}', { uuid: props.source.uuid })

	openDocument({
		fileUrl,
		filename: props.source.name,
		nodeId: props.source.nodeId ?? 0,
	})
}

defineExpose({
	actionsMenuStore,
	filesStore,
	showSigners,
	handleOpenDocument,
	mtime,
	openedMenu,
	mtimeOpacity,
	fileExtension,
	onRightClick,
	openDetailsIfAvailable,
	actions,
	name,
	isRenaming,
	renamingSaving,
	onRename,
	onStartRename,
	onFileRenaming,
})
</script>
