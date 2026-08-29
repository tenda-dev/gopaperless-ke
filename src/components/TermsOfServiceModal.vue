<template>
	<NcModal v-if="open" :size="'large'" @close="$emit('close')">
		<div class="terms-modal-content">
			<h3>{{ t('libresign', 'Terms of Service') }}</h3>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcLoadingIcon v-else-if="loading" :size="32" />
			<div v-else class="terms-body" v-html="body" />
			<div class="terms-modal-actions">
				<slot name="actions" />
			</div>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

defineProps<{
	open: boolean
	body: string
	loading: boolean
	error: string
}>()

defineEmits<{ close: [] }>()
</script>

<style lang="scss" scoped>
.terms-modal-content {
	padding: 24px;
	max-width: 720px;
	max-height: 80vh;
	display: flex;
	flex-direction: column;
	gap: 16px;

	h3 {
		margin: 0;
	}

	.terms-body {
		overflow-y: auto;
		max-height: 50vh;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		background: var(--color-background-dark);
	}

	.terms-modal-actions {
		display: flex;
		justify-content: flex-end;
		gap: 12px;
	}
}
</style>
