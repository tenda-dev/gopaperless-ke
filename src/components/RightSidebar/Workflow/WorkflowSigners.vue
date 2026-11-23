<template>
	<div class="workflow-signers">
		<!-- ========================================= -->
		<!-- HEADER -->
		<!-- ========================================= -->
		<div class="workflow-signers-header">
			<div class="workflow-signers-title">
				{{ t('libresign', 'Signers') }}
			</div>

			<NcButton v-if="canAddSigner" variant="primary" size="small" class="workflow-add-signer-button"
				:disabled="deletePending"
				@click="$emit('add-signer')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" :size="18" />
				</template>

				{{ t('libresign', 'Add signer') }}
			</NcButton>
		</div>

		<!-- ========================================= -->
		<!-- SIGN ORDER -->
		<!-- ========================================= -->
		<div v-if="showSigningOrder && canManageSigners" class="workflow-sign-order" :class="{
			'workflow-sign-order--active':
				isOrderedNumeric,
		}">
			<div class="workflow-sign-order-label">
				{{ t('libresign', 'Set the signing order') }}
			</div>

			<NcCheckboxRadioSwitch :model-value="isOrderedNumeric" type="switch"
				@update:modelValue="toggleSigningOrder" />
		</div>

		<!-- ========================================= -->
		<!-- DRAGGABLE -->
		<!-- ========================================= -->
		<draggable v-if="isOrderedNumeric && canReorder" v-model="sortableSigners" item-key="localKey" tag="div"
			handle=".workflow-signer-drag-handle" class="workflow-signer-list" chosenClass="workflow-signer-dragging"
			:disabled="dragLocked"
			dragClass="workflow-signer-drag-ghost" animation="220" @end="onDragEnd">
			<template #item="{ element: signer, index }">
				<WorkflowSigner
					:signer="signer"
					:index="index"
					:event="event"
					:is-ordered-numeric="isOrderedNumeric"
					:show-drag-handle="!signer.signed"
					:can-edit="canEditSigner(signer)"
					:can-delete="canDeleteSigner(signer)"
					:can-request-signature="canRequestSignature(signer)"
					:can-send-reminder="canSendReminder(signer)"
					:can-customize-message="canCustomizeMessage(signer)"
					:can-share="canShare(signer)"
					:can-act-now="controller.canSignerActInOrder(signer)"
					:file-id="Number(file?.id ?? 0)"
					:file-is-draft="file?.status === 0"
					:methods="controller.methods.value"
					:pending-action="pendingActions[signer.localKey as string] ?? null"
					:delete-blocked="requestBusy"
					:delete-pending="deletePending"
					@edit="$emit('edit-signer', signer)"
					@delete="$emit('delete-signer', signer)"
					@request-signature="$emit('request-signature', signer)"
					@send-reminder="$emit('send-reminder', signer)"
					@send-whatsapp="$emit('send-whatsapp', signer)"
					@customize-message="$emit('customize-message', signer)" />
			</template>
		</draggable>

		<!-- ========================================= -->
		<!-- STATIC -->
		<!-- ========================================= -->
		<TransitionGroup
			v-else
			name="workflow-signer-transition"
			tag="div"
			class="workflow-signer-list workflow-signer-list--animated"
			:css="listMotion"
			@before-leave="pinLeavingRow"
			@leave-cancelled="unpinRow">
			<WorkflowSigner
			    v-for="(signer, index) in signers"
				:key="signer.localKey"
				:signer="signer" :index="index"
				:event="event"
				:is-ordered-numeric="isOrderedNumeric"
				:can-edit="canEditSigner(signer)"
				:can-delete="canDeleteSigner(signer)"
				:can-request-signature="canRequestSignature(signer)"
				:can-send-reminder="canSendReminder(signer)"
				:can-customize-message="canCustomizeMessage(signer)"
					:can-share="canShare(signer)"
					:can-act-now="controller.canSignerActInOrder(signer)"
					:file-id="Number(file?.id ?? 0)"
					:file-is-draft="file?.status === 0"
				:methods="controller.methods.value"
				:pending-action="pendingActions[signer.localKey as string] ?? null"
				:delete-blocked="requestBusy"
				:delete-pending="deletePending"
				@edit="$emit('edit-signer', signer)"
				@delete="$emit('delete-signer', signer)"
				@request-signature="$emit('request-signature', signer)"
				@send-reminder="$emit('send-reminder', signer)"
					@send-whatsapp="$emit('send-whatsapp', signer)"
				@customize-message="$emit('customize-message', signer)" />
		</TransitionGroup>
	</div>
</template>

<script setup lang="ts">
import { computed, type PropType } from 'vue'

import { useMotionGuard } from '../../../composables/useMotionGuard'
import type { MotionKey } from '../../../composables/useMotionGuard'

import draggable from 'vuedraggable'

import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch
	from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper
	from '@nextcloud/vue/components/NcIconSvgWrapper'

import {
	mdiPlus,
} from '@mdi/js'

import WorkflowSigner from './WorkflowSigner.vue'

import { useFilesStore } from '../../../store/files'
import { useWorkflowController } from '../../../composables/useWorkflowController'

import type {
	SignatureFlowValue,
} from '../../../types'

import type {
	EditableSignerDraft,
} from '../../../store/files'

defineOptions({
	name: 'WorkflowSigners',
})

function normalizeSignatureFlow(
	flow: SignatureFlowValue | number | null | undefined,
): SignatureFlowValue | null | undefined {
	if (typeof flow === 'number') {
		const flowMap: Record<number, SignatureFlowValue> = {
			0: 'none',
			1: 'parallel',
			2: 'ordered_numeric',
		}

		return flowMap[flow]
	}

	return flow
}

const props = defineProps({
	canManageSigners: {
		type: Boolean,
		default: false,
	},
	signers: {
		type: Array as PropType<EditableSignerDraft[]>,
		required: true,
	},
	event: {
		type: String,
		default: '',
	},
	/** Pending row actions keyed by signer.localKey (owned by the tab's controller). */
	pendingActions: {
		type: Object as PropType<Record<string, 'notify' | 'whatsapp' | 'delete'>>,
		default: () => ({}),
	},
	/** A request-level mutation or delete is in flight. */
	requestBusy: {
		type: Boolean,
		default: false,
	},
	/** A request-level mutation (e.g. submitting the request) is in flight. Locks drag only. */
	requestLoading: {
		type: Boolean,
		default: false,
	},
   /**
	* Suppresses list transitions when switching documents. Without a key,
	* the list retains its existing transition behaviour.
	*/
	motionKey: {
		type: [String, Number] as PropType<MotionKey>,
		default: undefined,
	},
})

const { optedIn, motionEnabled } = useMotionGuard(() => props.motionKey)
const listMotion = computed(() => !optedIn.value || motionEnabled.value)

/**
 * Capture the row's geometry before it leaves the layout so it can fade
 * in place while its siblings move up. Measuring before absolute positioning
 * preserves its current layout position, including during an existing move.
 */
function pinLeavingRow(el: Element): void {
	if (!listMotion.value || !(el instanceof HTMLElement)) {
		return
	}
	el.style.top = `${el.offsetTop}px`
	el.style.left = `${el.offsetLeft}px`
	el.style.width = `${el.offsetWidth}px`
	el.style.height = `${el.offsetHeight}px`
}

/** Clear the pinned geometry if Vue cancels the leave and reuses the row. */
function unpinRow(el: Element): void {
	if (!(el instanceof HTMLElement)) {
		return
	}
	el.style.top = ''
	el.style.left = ''
	el.style.width = ''
	el.style.height = ''
}

const emit = defineEmits<{
	(e: 'add-signer'): void
	(e: 'edit-signer', signer: EditableSignerDraft): void
	(e: 'delete-signer', signer: EditableSignerDraft): void
	(e: 'request-signature', signer: EditableSignerDraft): void
	(e: 'send-reminder', signer: EditableSignerDraft): void
	(e: 'send-whatsapp', signer: EditableSignerDraft): void
	(e: 'customize-message', signer: EditableSignerDraft): void
	(e: 'toggle-sign-order', enabled: boolean): void
	(e: 'signing-order-changed'): void
}>()

const filesStore = useFilesStore()

const controller = useWorkflowController()

const file = computed(() => filesStore.getFile())

const isOriginalFileDeleted = computed(() => filesStore.isOriginalFileDeleted())

const sortableSigners = computed({
	get() {
		return props.signers
	},

	set(value) {
		if (file.value) {
			file.value.signers = value
		}
	},
})

/** A signer delete is in flight: drag reorder, Add and Edit entry points are locked. */
const deletePending = computed(() => Object.values(props.pendingActions).includes('delete'))

/** Reordering is locked during a delete or a request-level mutation (not during autosave). */
const dragLocked = computed(() => deletePending.value || props.requestLoading)

const isOrderedNumeric = computed(() => {
	const flow = normalizeSignatureFlow(
		file.value?.signatureFlow,
	)

	return flow === 'ordered_numeric'
})

const canReorder = computed(() => {
	return (
		filesStore.canSave()
		&& (props.signers?.length || 0) > 1
	)
})

const showSigningOrder = computed(() => {
	return (props.signers?.length || 0) > 1
})

const canAddSigner = computed(() => {
	return filesStore.canAddSigner()
})

function toggleSigningOrder(enabled: boolean) {
	emit('toggle-sign-order', enabled)
}

function onDragEnd(evt: {
	oldIndex: number
	newIndex: number
}) {
	if (dragLocked.value) {
		return
	}
	const {
		oldIndex,
		newIndex,
	} = evt

	if (oldIndex === newIndex) {
		return
	}

	file.value?.signers?.forEach((signer, index) => {
		signer.signingOrder = index + 1
	})

	emit('signing-order-changed')
}

function canEditSigner(signer: EditableSignerDraft) {
    if (!props.canManageSigners) {
		return false
	}

	if (isOriginalFileDeleted.value) {
		return false
	}

	return !controller.isSignerSigned(signer)
}

function canDeleteSigner(signer: EditableSignerDraft) {
	if (!props.canManageSigners) {
		return false
	}

	if (isOriginalFileDeleted.value) {
		return false
	}

	return (
		filesStore.canSave()
		&& !controller.isSignerSigned(signer)
	)
}

function canRequestSignature(signer: EditableSignerDraft) {
	if (
		isOriginalFileDeleted.value
		|| !filesStore.canRequestSign
		|| file.value?.status === 0
		|| controller.isSignerSigned(signer)
		|| !signer?.signRequestId
		|| signer?.me
		|| signer?.status !== 0
		|| !props.canManageSigners
	) {
		return false
	}

	return controller.canSignerActInOrder(signer)
}

function canSendReminder(signer: EditableSignerDraft) {
	if (
		isOriginalFileDeleted.value
		|| !filesStore.canRequestSign
		|| file.value?.status === 0
		|| controller.isSignerSigned(signer)
		|| !signer?.signRequestId
		|| signer?.me
		|| signer?.status !== 1
		|| !props.canManageSigners
	) {
		return false
	}

	return controller.canSignerActInOrder(signer)
}

// Administration > LibreSign > WhatsApp signing requests.
const whatsAppEnabled = loadState<boolean>('libresign', 'whatsapp_enabled', false)

/** WhatsApp, Copy link and Show QR: whoever manages signers, on a single unsigned document. */
function canShare(signer: EditableSignerDraft) {
	return Boolean(
		whatsAppEnabled
		&& props.canManageSigners
		&& !isOriginalFileDeleted.value
		&& filesStore.canRequestSign
		&& file.value?.nodeType !== 'envelope'
		&& signer?.signRequestId
		&& !signer?.me
		&& !controller.isSignerSigned(signer),
	)
}

function canCustomizeMessage(signer: EditableSignerDraft) {
	if (!props.canManageSigners) {
		return false
	}
	if (isOriginalFileDeleted.value) {
		return false
	}

	if (
		controller.isSignerSigned(signer)
		|| !signer.signRequestId
		|| signer.me
	) {
		return false
	}

	return controller.canSignerActInOrder(signer)
}
</script>

<style scoped lang="scss">
.workflow-signers {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.workflow-signers-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;

	& .button-vue {
		border: 1px solid var(--color-border);
	}
}

.workflow-signers-title {
	font-size: 12px;
	font-weight: 800;
	letter-spacing: 0.08em;
	text-transform: uppercase;

	color: var(--color-text-maxcontrast);

	opacity: 0.75;
}

.workflow-add-signer-button {
	flex-shrink: 0;
}

.workflow-sign-order {
	display: flex;
	align-items: center;
	justify-content: space-between;

	padding: 4px 10px;

	border-radius: 10px;

	border:
		1px solid rgba(0, 0, 0, 0.06);

	background:
		rgba(120, 120, 120, 0.04);

	transition:
		background var(--gp-motion-base, 180ms) ease,
		border-color var(--gp-motion-base, 180ms) ease,
		box-shadow var(--gp-motion-base, 180ms) ease;
}

.workflow-sign-order--active {
	border-color:
		rgba(124, 58, 237, 0.18);

	box-shadow:
		0 0 0 4px rgba(124, 58, 237, 0.05);
}

.workflow-sign-order-label {
	font-size: 14px;
	font-weight: 600;
}

.workflow-signer-list {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

/* =========================================
 * TRANSITIONS
 * ========================================= */

/*
 * Row enter / leave / move (opacity + transform only).
 * The `--animated` prefix raises specificity above WorkflowSigner's own row
 * `transition` list, so these win regardless of stylesheet order. It exists
 * only on the static (TransitionGroup) list, never on the draggable one.
 */
.workflow-signer-list--animated {
	position: relative;
}

.workflow-signer-list--animated .workflow-signer-transition-enter-active {
	transition:
		opacity var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
		transform var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
}

/* top/left/width/height are set inline in pinLeavingRow before this applies. */
.workflow-signer-list--animated .workflow-signer-transition-leave-active {
	position: absolute;
	box-sizing: border-box;
	margin: 0;

	pointer-events: none;

	transition:
		opacity var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1)),
		transform var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
}

.workflow-signer-list--animated .workflow-signer-transition-enter-from {
	opacity: 0;

	transform:
		translateY(10px) scale(0.985);
}

.workflow-signer-list--animated .workflow-signer-transition-leave-to {
	opacity: 0;

	transform:
		scale(0.98);
}

.workflow-signer-list--animated .workflow-signer-transition-move {
	transition:
		transform var(--gp-motion-slow, 260ms) var(--gp-ease, cubic-bezier(0.2, 0, 0, 1));
}

/* =========================================
 * DRAGGING
 * ========================================= */

:deep(.workflow-signer-dragging) {
	opacity: 0.45;

	transform: scale(0.985);

	filter: saturate(0.9);
}

:deep(.workflow-signer-drag-ghost) {
	border-radius: 24px;

	box-shadow:
		0 18px 40px rgba(0, 0, 0, 0.12);

	transform: rotate(-1deg);
}
</style>
