<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!-- SecurySign's frame is the whole window: it carries its own name,
		 document, button and Cancel, so nothing of ours goes around it. -->
	<NcModal size="small" @close="emit('cancel')">
		<iframe ref="frame"
			:src="src"
			:title="t('libresign', 'SecurySign signing')"
			class="securysign-approval__frame"
			:style="{ height: `${height}px` }"
			allow="publickey-credentials-get *"
			sandbox="allow-scripts allow-same-origin allow-forms allow-popups" />
	</NcModal>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

export type SecurySignApprovalRequest = {
	token: string
	documentHash: string
	documentName: string
	origin: string
}

const props = defineProps<{ approval: SecurySignApprovalRequest }>()
const emit = defineEmits(['approved', 'cancel'])

const frame = ref<HTMLIFrameElement | null>(null)
const height = ref(600)
let started = false

// The contract is SecurySign's "Sign in the browser" guide: the frame runs the
// passkey prompt on its own origin and reports back with postMessage.
const src = computed(() => `${props.approval.origin}/#/sign-frame?${new URLSearchParams({
	documentHash: props.approval.documentHash,
	documentName: props.approval.documentName,
	mode: 'registered',
	rpOrigin: window.location.origin,
	token: props.approval.token,
})}`)

function onMessage(event: MessageEvent) {
	if (event.origin !== props.approval.origin || event.source !== frame.value?.contentWindow) {
		return
	}
	const message = event.data ?? {}
	if (message.type === 'SSC_RESIZE') {
		if (message.height) {
			height.value = message.height
		}
		// The frame's first size report means it is listening. Asking it to sign
		// opens the passkey prompt without a click on the frame's own button.
		if (!started) {
			started = true
			frame.value?.contentWindow?.postMessage({
				type: 'SSC_SIGN_REQUEST',
				documentHash: props.approval.documentHash,
				documentName: props.approval.documentName,
				mode: 'registered',
				token: props.approval.token,
			}, props.approval.origin)
		}
	} else if (message.type === 'SSC_SIGN_COMPLETE' && message.documentHash === props.approval.documentHash) {
		// The server verifies this against the user's certificate before using it.
		emit('approved', message.signatureBase64)
	} else if (message.type === 'SSC_CLOSE_FRAME') {
		emit('cancel')
	}
	// SSC_SIGN_ERROR needs nothing here: the frame shows the error and a retry.
}

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => window.removeEventListener('message', onMessage))
</script>

<style scoped lang="scss">
.securysign-approval__frame {
	display: block;
	width: 100%;
	border: 0;
}
</style>

<style lang="scss">
// The modal is exactly as wide as SecurySign's card (440px), so no strip of
// white shows beside it. Unscoped: NcModal is teleported out of this component.
// The doubled class outranks NcModal's `.modal-wrapper--small > .modal-container`.
.modal-wrapper .modal-container.modal-container:has(.securysign-approval__frame) {
	width: 440px;
	max-width: 100%;
}
</style>
