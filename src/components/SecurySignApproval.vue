<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog :name="t('libresign', 'Approve with SecurySign')" size="normal"
		dialog-classes="libresign-dialog" @closing="emit('cancel')">
		<p class="securysign-approval__hint">
			{{ t('libresign', 'Your passkey approves this signature. The document is signed with your SecurySign certificate.') }}
		</p>
		<iframe ref="frame"
			:src="src"
			title="SecurySign signing"
			class="securysign-approval__frame"
			:style="{ height: `${height}px` }"
			allow="publickey-credentials-get *"
			sandbox="allow-scripts allow-same-origin allow-forms allow-popups" />
	</NcDialog>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
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
const height = ref(420)
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
.securysign-approval__hint {
	margin-bottom: 12px;
}

.securysign-approval__frame {
	width: 100%;
	border: 0;
}
</style>
