<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="identifySigner">
		<SignerSelect v-if="isNewSigner"
			:placeholder="placeholder"
			:method="method"
			@update:signer="applySelectedSigner"
			@phone-not-found="handlePhoneNotFound" />
		<NcNoteCard v-else type="info">
			<template #icon>
				<NcIconSvgWrapper :size="20" :svg="getMethodIcon()" />
			</template>
			<strong>{{ identifyMethodLabel }}:</strong> {{ identify }}
		</NcNoteCard>

		<NcNoteCard v-if="disabled" type="warning" class="disabled-warning">
			{{ t('libresign', 'This signer cannot be used because the identification method "{method}" has been disabled by the administrator.', { method: identifyMethodLabel }) }}
		</NcNoteCard>

		<NcTextField v-if="signerSelected && !disabled"
			v-model="displayName"
			aria-describedby="name-input"
			autocapitalize="none"
			maxlength="64"
			:label="t('libresign', 'Signer name')"
			:required="true"
			:error="nameHaveError"
			:helper-text="nameHelperText"
			@update:modelValue="onNameChange" />

		<div v-if="whatsAppOffered && !disabled" class="whatsapp" :class="{ 'whatsapp--on': whatsAppSend }">
			<div class="whatsapp__row">
				<span class="whatsapp__logo" aria-hidden="true">
					<NcLoadingIcon v-if="whatsAppChecking" :size="20" appearance="light" />
					<NcIconSvgWrapper v-else :size="22" :svg="svgWhatsapp" />
				</span>
				<div class="whatsapp__text">
					<strong>{{ t('libresign', 'Send sign request on Whatsapp') }}</strong>
					<span class="whatsapp__hint">{{ whatsAppHint }}</span>
				</div>
				<NcCheckboxRadioSwitch v-model="whatsAppSend"
					type="switch"
					:disabled="!whatsAppUsable">
					<span class="hidden-visually">{{ t('libresign', 'Send sign request on Whatsapp') }}</span>
				</NcCheckboxRadioSwitch>
			</div>
			<NcTextField v-if="whatsAppSend"
				v-model="whatsAppTo"
				type="tel"
				autocomplete="off"
				:label="t('libresign', 'WhatsApp number')"
				:placeholder="whatsAppAccount?.masked ?? '0712 345 678'"
				:error="whatsAppToError !== ''"
				:helper-text="whatsAppToError" />
		</div>

		<NcNoteCard v-if="sponsorshipEnabled && isRequester" type="info">
			<template #icon>
				<NcIconSvgWrapper :size="20" :svg="svgAccount" />
			</template>

			{{
				t(
					'libresign',
					'Your signing credit will automatically be reserved when you request this document.',
				)
			}}
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-else-if="sponsorshipEnabled" :model-value="requesterSponsored" type="switch"
			@update:model-value="onToggleSponsoredSigner">
			{{ t('libresign', 'Pay for this signer') }}
		</NcCheckboxRadioSwitch>

		<div v-if="signerSelected && showCustomMessage && !disabled" class="description-wrapper">
			<NcCheckboxRadioSwitch v-model="enableCustomMessage"
				type="switch"
				@update:model-value="onToggleCustomMessage">
				{{ t('libresign', 'Add custom message') }}
			</NcCheckboxRadioSwitch>
			<NcTextArea v-if="enableCustomMessage"
				v-model="description"
				aria-describedby="description-input"
				maxlength="500"
				:label="t('libresign', 'Custom message')"
				:placeholder="t('libresign', 'Add a personal message for this signer')"
				:rows="3"
				resize="none" />
		</div>

		<div v-if="!disabled" class="identifySigner__footer">
			<div class="button-group">
				<NcButton @click="filesStore.disableIdentifySigner()">
					{{ t('libresign', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!signerSelected || loading || whatsAppInvalid"
					@click="saveSigner">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
					</template>

					{{ saveButtonText }}
				</NcButton>
			</div>
		</div>
	</div>
</template>
<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { computed, onBeforeMount, ref, type ComputedRef } from 'vue'

import svgAccount from '@mdi/svg/svg/account.svg?raw'
import svgEmail from '@mdi/svg/svg/email.svg?raw'
import svgSms from '@mdi/svg/svg/message-processing.svg?raw'
import svgWhatsapp from '@mdi/svg/svg/whatsapp.svg?raw'
import svgXmpp from '@mdi/svg/svg/xmpp.svg?raw'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import SignerSelect from './SignerSelect.vue'

import svgSignal from '../../../img/logo-signal-app.svg?raw'
import svgTelegram from '../../../img/logo-telegram-app.svg?raw'
import { SIGN_REQUEST_STATUS } from '../../constants.js'
import { useFilesStore } from '../../store/files.js'
import { getSignRequestStatusText } from '../../utils/getSignRequestStatusText.ts'
import type {
	IdentifyAccountRecord,
	Sponsorship
} from '../../types'
import { showError } from '../../services/toast'
import {
	chooseWhatsApp,
	fetchWhatsAppAccount,
	normalizeWhatsAppNumber,
	type WhatsAppAccount,
} from '../../services/whatsapp'
import { emit as emitEventBus } from '@nextcloud/event-bus'

import { useUserContextStore } from '@/store/userContext'

const iconMap = {
	svgAccount,
	svgEmail,
	svgSignal,
	svgSms,
	svgTelegram,
	svgWhatsapp,
	svgXmpp,
}

const sponsorshipEnabled = (
	loadState('libresign', 'config', {}) as {
		sponsorship_enabled?: boolean
	}
).sponsorship_enabled ?? false

const methodIconMap: Record<string, keyof typeof iconMap> = {
	account: 'svgAccount',
	email: 'svgEmail',
	signal: 'svgSignal',
	sms: 'svgSms',
	telegram: 'svgTelegram',
	whatsapp: 'svgWhatsapp',
	xmpp: 'svgXmpp',
}

defineOptions({
	name: 'IdentifySigner',
})

const emit = defineEmits<{
	(event: 'phone-not-found', phone: string): void
}>()

type IdentifyMethodConfig = {
	name: string
	friendly_name?: string
}

type SignerMethodValue = {
	method: string
	value: string
}

type SignerToEdit = {
	displayName?: string
	description?: string
	identifyMethods?: SignerMethodValue[]
	sponsorship?: Sponsorship | null
}

type FilesStore = ReturnType<typeof useFilesStore>
type StoredSigner = NonNullable<ReturnType<FilesStore['getFile']>['signers']>[number]

const props = withDefaults(defineProps<{
	signerToEdit?: SignerToEdit
	method?: string
	placeholder?: string
	methods?: IdentifyMethodConfig[]
	disabled?: boolean
}>(), {
	signerToEdit: () => ({
		displayName: '',
		identifyMethods: [],
	}),
	method: 'all',
	placeholder: t('libresign', 'Name'),
	methods: () => [],
	disabled: false,
})

const filesStore = useFilesStore()
const userContextStore = useUserContextStore()

const loading = ref(false)
const nameHelperText = ref('')
const nameHaveError = ref(false)
const displayName = ref('')
const description = ref('')
const requesterSponsored = ref(sponsorshipEnabled)
const enableCustomMessage = ref(false)
const identify = ref('')
const identifyMethod = ref<IdentifyAccountRecord['method'] | undefined>()
const acceptsEmailNotifications = ref<boolean | undefined>()

const signerSelected = computed(() => identify.value.length > 0)
const isNewSigner = computed(() => !props.signerToEdit || Object.keys(props.signerToEdit).length === 0)
const saveButtonText = computed(() => isNewSigner.value ? t('libresign', 'Save') : t('libresign', 'Update'))
const identifyMethodLabel = computed(() => {
	if (!identifyMethod.value) {
		return ''
	}
	const methodConfig = props.methods.find((item) => item.name === identifyMethod.value)
	if (!methodConfig?.friendly_name) {
		return ''
	}
	return methodConfig.friendly_name
})
const showCustomMessage = computed(() => {
	if (identifyMethod.value === 'account') {
		return acceptsEmailNotifications.value === true
	}
	return !!identifyMethod.value
})

const isRequester = computed(() => {
	return identifyMethod.value === 'account'
		&& identify.value === userContextStore.uid
})

const sponsorship = computed(() => {
	if (!sponsorshipEnabled) {
		return {
			type: 'self',
		} satisfies Sponsorship
	}

	if (isRequester.value) {
		return {
			type: 'requester',
		} satisfies Sponsorship
	}

	return {
		type: requesterSponsored.value
			? 'requester'
			: 'self',
	} satisfies Sponsorship
})

/* ── "Send sign request on Whatsapp" ───────────────────────────────────────── */

// Administration > LibreSign > WhatsApp signing requests.
const whatsAppEnabled = loadState<boolean>('libresign', 'whatsapp_enabled', false)
const whatsAppAccount = ref<WhatsAppAccount | null>(null)
const whatsAppChecking = ref(false)
const whatsAppSend = ref(false)
const whatsAppTo = ref('')

/** Someone added by account: anyone else would sign with the local engine, not through the link. */
const whatsAppOffered = computed(() => whatsAppEnabled
	&& isNewSigner.value
	&& identifyMethod.value === 'account'
	&& !isRequester.value
	&& filesStore.getFile()?.nodeType !== 'envelope')

/** A number on file, none yet, or the check failed: the last two can still type one. */
const whatsAppUsable = computed(() => !whatsAppChecking.value && Boolean(
	whatsAppAccount.value?.available
	|| ['no_number', 'unavailable'].includes(whatsAppAccount.value?.reason ?? ''),
))

const whatsAppHint = computed(() => {
	const account = whatsAppAccount.value
	if (whatsAppChecking.value || !account) {
		return t('libresign', 'Checking for their WhatsApp number…')
	}
	if (account.available) {
		return t('libresign', 'Number on file: {number}', { number: account.masked ?? '' })
	}
	switch (account.reason) {
	case 'no_number':
		return t('libresign', 'No WhatsApp number on file. You can type theirs.')
	case 'not_account':
	case 'password_account':
		return t('libresign', 'WhatsApp needs a MIMI account')
	case 'no_sub':
		return t('libresign', 'WhatsApp once they sign in to GoPaperless')
	default:
		return t('libresign', 'Couldn\'t check for a number. You can type theirs.')
	}
})

/** The field still holds the masked number it was filled with: send to the number on file. */
const whatsAppOnFile = computed(() => Boolean(whatsAppAccount.value?.available) && whatsAppTo.value === whatsAppAccount.value?.masked)
const whatsAppToError = computed(() => whatsAppTo.value.trim() !== '' && !whatsAppOnFile.value && !normalizeWhatsAppNumber(whatsAppTo.value)
	? t('libresign', 'Enter a Kenyan mobile number, like 0712 345 678')
	: '')
const whatsAppInvalid = computed(() => whatsAppOffered.value && whatsAppSend.value && (
	whatsAppToError.value !== ''
	|| (!whatsAppAccount.value?.available && whatsAppTo.value.trim() === '')
))

function resetWhatsApp() {
	whatsAppAccount.value = null
	whatsAppChecking.value = false
	whatsAppSend.value = false
	whatsAppTo.value = ''
}

async function checkWhatsApp() {
	resetWhatsApp()
	if (!whatsAppOffered.value) {
		return
	}
	const uid = identify.value
	whatsAppChecking.value = true
	const account = await fetchWhatsAppAccount(uid)
	if (identify.value !== uid) {
		return // another signer was picked meanwhile; its own check owns the state
	}
	whatsAppAccount.value = account
	whatsAppTo.value = account.available ? account.masked ?? '' : ''
	whatsAppChecking.value = false
}

/** After the signer is saved: the server sends when they are asked to sign. */
async function rememberWhatsApp(uid: string, to: string) {
	const file = filesStore.getFile()
	const signer = (file?.signers ?? []).find((item: StoredSigner) =>
		item.identifyMethods?.some(m => m.method === 'account' && m.value === uid))
	try {
		if (!file?.id || !signer?.signRequestId) {
			throw new Error('The new signer has no sign request yet')
		}
		await chooseWhatsApp(Number(file.id), signer.signRequestId, to)
		emitEventBus('libresign:whatsapp-changed', { signRequestId: signer.signRequestId, status: 'chosen' })
	} catch {
		showError(t('libresign', 'The signer was added, but WhatsApp could not be turned on for them'))
	}
}

function onToggleSponsoredSigner(
	enabled: boolean,
) {
	requesterSponsored.value = enabled
}

function buildSigner(): StoredSigner {
	return {
		displayName: displayName.value,
		description: description.value.trim() || undefined,

		sponsorship: sponsorship.value,

		...(identifyMethod.value === 'email'
			? { email: identify.value }
			: {}),

		identifyMethods: [
			{
				method: identifyMethod.value!,
				mandatory: 0,
				value: identify.value,
			},
		],
	}
}

function resetNameValidation() {
	nameHelperText.value = ''
	nameHaveError.value = false
}

function resetSelectedSignerState() {
	displayName.value = ''
	description.value = ''
	requesterSponsored.value = sponsorshipEnabled
	enableCustomMessage.value = false
	identify.value = ''
	identifyMethod.value = undefined
	acceptsEmailNotifications.value = undefined
	resetNameValidation()
	resetWhatsApp()
}

function getMethodIcon() {
	const method = identifyMethod.value
	if (!method) {
		return iconMap.svgAccount
	}
	const iconKey = methodIconMap[method] || 'svgAccount'
	return iconMap[iconKey]
}


function applySelectedSigner(nextSigner: IdentifyAccountRecord | null) {
	if (!nextSigner) {
		resetSelectedSignerState()
		return
	}

	displayName.value = nextSigner.displayName ?? ''
	identify.value = nextSigner.identify ?? ''
	identifyMethod.value = nextSigner.method
	acceptsEmailNotifications.value =
		nextSigner.acceptsEmailNotifications

	/**
	 * The requester always sponsors themselves — but only when the
	 * sponsorship feature is enabled.
	 *
	 * This is a presentation rule only—the backend still remains the
	 * source of truth—but keeping the local state aligned avoids stale
	 * toggle values if the user previously selected another signer.
	 */
	if (
		sponsorshipEnabled
		&& nextSigner.method === 'account'
		&& nextSigner.identify === userContextStore.uid
	) {
		requesterSponsored.value = true
	}

	resetNameValidation()

	if (
		nextSigner.method === 'account'
		&& nextSigner.acceptsEmailNotifications === false
	) {
		enableCustomMessage.value = false
		description.value = ''
	}

	void checkWhatsApp()
}

function getSignerToEditIdentify(signerToEdit: SignerToEdit | undefined): string {
	if (!signerToEdit) {
		return ''
	}
	return signerToEdit.identifyMethods?.[0]?.value ?? ''
}

async function saveSigner() {
	if (loading.value) {
		return
	}
	if (!identifyMethod.value || !identify.value || whatsAppInvalid.value) {
		return
	}
	const uid = identify.value
	const whatsAppNumber = whatsAppOffered.value && whatsAppSend.value
		? (whatsAppOnFile.value ? '' : whatsAppTo.value.trim())
		: null
	loading.value = true
	const file = filesStore.getFile()
	const signers: StoredSigner[] = Array.isArray(file?.signers) ? [...file.signers] : []
	signers.push({
		...buildSigner(),

		status: SIGN_REQUEST_STATUS.DRAFT,
		statusText: getSignRequestStatusText(
			SIGN_REQUEST_STATUS.DRAFT,
		),
	})

	try {
		const response = await filesStore.saveOrUpdateSignatureRequest({ signers })
		if ('success' in response && response.success === false) {
			showError(response.message ?? t('libresign', 'Failed to save or update signature request'))
			return
		}
		if (whatsAppNumber !== null) {
			await rememberWhatsApp(uid, whatsAppNumber)
		}
	} catch {
		showError(t('libresign', 'Failed to save or update signature request'))
		return
	} finally {
		loading.value = false
	}

	resetSelectedSignerState()
	filesStore.disableIdentifySigner()
}

function onNameChange() {
	const name = displayName.value.trim()
	if (name.length > 2) {
		nameHelperText.value = ''
		nameHaveError.value = false
		return
	}
	nameHelperText.value = t('libresign', 'Please enter signer name.')
	nameHaveError.value = true
}

function onToggleCustomMessage(checked: boolean) {
	if (!checked) {
		description.value = ''
	}
}

function handlePhoneNotFound(phone: string) {
	emit('phone-not-found', phone)
}

onBeforeMount(async () => {
	/**
	 * Ensure the authenticated user context is available.
	 *
	 * initialise() is idempotent, so this is effectively a no-op
	 * once another part of the application has already hydrated
	 * the store.
	 */
	await userContextStore.initialise()

	if (!props.signerToEdit) {
		return
	}
	displayName.value = props.signerToEdit.displayName ?? ''
	description.value = props.signerToEdit.description ?? ''
	enableCustomMessage.value = !!props.signerToEdit.description
	identify.value = getSignerToEditIdentify(props.signerToEdit)
	if (Object.keys(props.signerToEdit).length > 0 && props.signerToEdit.identifyMethods?.length) {
		const method = props.signerToEdit.identifyMethods[0]
		identifyMethod.value = method.method as IdentifyAccountRecord['method']
	}
	requesterSponsored.value = sponsorshipEnabled && (
		isNewSigner.value
			? true
			: props.signerToEdit?.sponsorship?.type === 'requester'
	)
})

defineExpose({
	props,
	t,
	filesStore,
	nameHelperText,
	nameHaveError,
	displayName,
	description,
	enableCustomMessage,
	identify,
	identifyMethod,
	acceptsEmailNotifications,
	signerSelected,
	isNewSigner,
	saveButtonText,
	identifyMethodLabel,
	showCustomMessage,
	getMethodIcon,
	resetNameValidation,
	resetSelectedSignerState,
	applySelectedSigner,
	saveSigner,
	onNameChange,
	onToggleCustomMessage,
})
</script>

<style lang="scss" scoped>
.identifySigner {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 96%;
	margin: 0 auto;
	padding: 24px 0;

	.disabled-warning {
		margin-top: 12px;
		width: 100%;
	}

	#account-or-email {
		width: 100%;
	}

	:deep(.notecard) {
		width: 100%;
		margin-bottom: 16px;

		div {
			display: flex;
			align-items: center;
			gap: 0.5em;
		}
	}
	.whatsapp {
		width: 100%;
		box-sizing: border-box;
		margin: 12px 0 16px;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		transition: border-color 0.2s;

		&--on {
			border-color: #25d366;
		}

		&__row {
			display: flex;
			align-items: center;
			gap: 12px;
		}

		&__logo {
			display: flex;
			flex-shrink: 0;
			align-items: center;
			justify-content: center;
			width: 40px;
			height: 40px;
			border-radius: 50%;
			background: #25d366; // WhatsApp green
			color: #fff;
		}

		&__text {
			display: flex;
			flex: 1;
			flex-direction: column;
			min-width: 0;
		}

		&__hint {
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}

		:deep(.input-field) {
			margin-top: 12px;
		}
	}

	.description-wrapper {
		width: 100%;
		margin-bottom: 16px;

		:deep(textarea) {
			margin-top: 8px;
		}
	}

	&__footer {
		width: 100%;
		display: flex;
		position: sticky;
		bottom: 0;
		flex-direction: column;
		justify-content: space-between;
		align-items: flex-start;
		background: linear-gradient(to bottom, rgba(255, 255, 255, 0), var(--color-main-background));
		padding-top: 24px;

		.button-group {
			display: flex;
			justify-content: space-between;
			width: 100%;
			margin-top: 16px;

			button {
				margin-left: 16px;

				&:first-child {
					margin-left: 0;
				}
			}
		}
	}
}
</style>
