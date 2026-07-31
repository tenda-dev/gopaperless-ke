<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal v-if="open"
		size="large"
		:name="t('libresign', 'Signature appearance')"
		@close="onClose">
		<div class="gp-appearance">
			<!-- Header -->
			<div class="gp-appearance__header">
				<h2 class="gp-appearance__title">
					{{ t('libresign', 'Signature appearance') }}
				</h2>
				<p class="gp-appearance__subtitle">
					{{ t('libresign', 'Configure customer-specific signature appearance. Select a customer group, review its current profile, adjust the rendering options, and save. One customer at a time.') }}
				</p>
			</div>

			<!-- Inline status note -->
			<div v-if="note"
				class="gp-appearance__note"
				:class="`gp-appearance__note--${note.type}`"
				role="status">
				<svg class="gp-appearance__note-icon" width="18" height="18"
					viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
					<path :d="note.type === 'success' ? mdiCheckCircle : mdiAlertCircle" />
				</svg>
				<span>{{ note.message }}</span>
			</div>

			<!-- Customer group picker -->
			<div class="gp-appearance__field">
				<label class="gp-appearance__label">{{ t('libresign', 'Customer group') }}</label>
				<NcSelect
					v-model="selectedGroup"
					label="displayname"
					:aria-label-combobox="t('libresign', 'Customer group')"
					:options="groups"
					:loading="loadingGroups"
					:searchable="true"
					:clearable="false"
					:show-no-options="false"
					@search="searchGroup" />
			</div>

			<!-- Profile summary -->
			<div v-if="selectedGroup" class="gp-appearance__summary">
				<span
					class="gp-appearance__badge"
					:class="hasCustomProfile ? 'gp-appearance__badge--custom' : 'gp-appearance__badge--default'">
					{{ hasCustomProfile ? t('libresign', 'Custom profile') : t('libresign', 'Default profile') }}
				</span>
				<span class="gp-appearance__members">
					<svg class="gp-appearance__members-icon" width="18" height="18"
						viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
						<path :d="mdiAccountGroupOutline" />
					</svg>
					{{ n('libresign', '%n member', '%n members', memberCount) }}
				</span>
			</div>

			<!-- Rendering options -->
			<template v-if="selectedGroup">
				<div class="gp-appearance__body">
					<div class="gp-appearance__pane gp-appearance__pane--preview">
						<div class="gp-appearance__preview">
							<div class="gp-appearance__preview-head">
						<span class="gp-appearance__label">{{ t('libresign', 'Preview') }}</span>
						<div class="gp-appearance__seg" role="tablist">
							<button
								type="button"
								class="gp-appearance__seg-btn"
								:class="{ 'gp-appearance__seg-btn--active': previewMode === 'document' }"
								@click="previewMode = 'document'">
								{{ t('libresign', 'Document') }}
							</button>
							<button
								type="button"
								class="gp-appearance__seg-btn"
								:class="{ 'gp-appearance__seg-btn--active': previewMode === 'stamp' }"
								@click="previewMode = 'stamp'">
								{{ t('libresign', 'Signature stamp') }}
							</button>
						</div>
					</div>

					<SignatureProfilePreview
						v-if="previewMode === 'document'"
						:footer="draft.footer"
						:qr="draft.footer && draft.qr"
						:stamp="draft.stamp.enabled"
						:audit-info="draft.footer && draft.auditInfo"
						:render-mode="effectiveStampRenderMode" />
					<SignatureStampPreview
						v-else
						:enabled="draft.stamp.enabled"
						:render-mode="effectiveStampRenderMode"
						:width="effectiveStampWidth"
						:height="effectiveStampHeight"
						:signature-font-size="effectiveStampSignatureFont"
						:template-font-size="effectiveStampTemplateFont"
							:template-text="effectiveStampTemplateText"
							:is-override="stampTemplateIsOverride" />
						</div>
					</div>

					<div class="gp-appearance__pane gp-appearance__pane--controls">
						<div class="gp-appearance__options-head">
					<span class="gp-appearance__options-title">{{ t('libresign', 'Rendering options') }}</span>
					<button
						type="button"
						class="gp-appearance__reset"
						:disabled="isDraftDefault"
						@click="resetToDefault">
						{{ t('libresign', 'Reset to default') }}
					</button>
				</div>

				<div class="gp-appearance__rows">
					<SignatureProfileRow
						v-model="draft.footer"
						:title="t('libresign', 'Footer')"
						:description="t('libresign', 'Validation footer band at the bottom of each page')" />
					<SignatureProfileRow
						v-model="draft.qr"
						indented
						:title="t('libresign', 'QR code')"
						:description="t('libresign', 'Validation QR inside the footer')" />
					<SignatureProfileRow
						v-model="draft.stamp.enabled"
						:title="t('libresign', 'Signature stamp')"
						:description="t('libresign', 'Visible signature image placed on the page')" />
					<SignatureStampConfig
						v-if="draft.stamp.enabled"
						:model-value="draft.stamp"
						:globals="stampGlobals"
						@update:model-value="draft.stamp = $event" />
					<SignatureProfileRow
						v-model="draft.auditInfo"
						:title="t('libresign', 'Audit info')"
						:description="t('libresign', 'Audit and validation details block')" />
				</div>

						<p class="gp-appearance__meta">
							{{ n('libresign', '%n group has a custom profile', '%n groups have a custom profile', customProfileCount) }}
						</p>
					</div>
				</div>

				<!-- Footer / actions -->
				<div class="gp-appearance__foot">
					<SignatureProfileFooter
						:dirty="dirty"
						:can-save="canSave"
						:changes="changeChips"
						:saving="saving"
						@discard="discard"
						@save="save" />
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { t, n } from '@nextcloud/l10n'
import { mdiAccountGroupOutline, mdiCheckCircle, mdiAlertCircle } from '@mdi/js'
import { computed, reactive, ref, watch } from 'vue'

import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import SignatureProfileRow from './SignatureProfileRow.vue'
import SignatureProfileFooter from './SignatureProfileFooter.vue'
import SignatureProfilePreview from './SignatureProfilePreview.vue'
import SignatureStampPreview from './SignatureStampPreview.vue'
import SignatureStampConfig from './SignatureStampConfig.vue'
import type { StampDraft, StampGlobals } from './SignatureStampConfig.vue'
import logger from '../../../logger.js'

import '@nextcloud/password-confirmation/style.css'

defineOptions({
	name: 'SignatureProfileModal',
})

export type Profile = {
	footer: boolean
	qr: boolean
	stamp: StampDraft
	auditInfo: boolean
}

/** Shape as stored in appConfig; */
export type RawProfile = {
	footer?: boolean
	qr?: boolean
	stamp?: boolean | Record<string, unknown>
	auditInfo?: boolean
}

type GroupRow = {
	id: string
	displayname: string
	usercount?: number
}

const props = withDefaults(defineProps<{
	open: boolean
	profiles?: Record<string, RawProfile>
}>(), {
	profiles: () => ({}),
})

const emit = defineEmits<{
	(event: 'update:open', value: boolean): void
	(event: 'update:profiles', value: Record<string, Profile>): void
}>()

/** Global stamp settings used as the inheritance baseline / input placeholders. */
const stampGlobals: StampGlobals = {
	renderMode: loadState<string>('libresign', 'signature_render_mode', 'GRAPHIC_AND_DESCRIPTION'),
	textTemplate: loadState<string>('libresign', 'signature_text_template', ''),
	signatureFontSize: loadState<number>('libresign', 'signature_font_size', 10),
	templateFontSize: loadState<number>('libresign', 'template_font_size', 10),
	width: loadState<number>('libresign', 'signature_width', 350),
	height: loadState<number>('libresign', 'signature_height', 100),
}

const BOOL_LABELS = {
	footer: t('libresign', 'Footer'),
	qr: t('libresign', 'QR code'),
	auditInfo: t('libresign', 'Audit info'),
} as const

/** Default stamp: enabled, all overrides empty (inherit global). */
function defaultStamp(): StampDraft {
	return {
		enabled: true,
		renderMode: '',
		textTemplate: '',
		signatureFontSize: '',
		templateFontSize: '',
		width: '',
		height: '',
	}
}

/** Default profile: everything rendered (default-on). */
function defaultProfile(): Profile {
	return { footer: true, qr: true, stamp: defaultStamp(), auditInfo: true }
}

function normalizeStamp(raw: boolean | Record<string, unknown> | undefined): StampDraft {
	if (typeof raw === 'boolean') {
		return { ...defaultStamp(), enabled: raw }
	}
	const obj = raw ?? {}
	const str = (value: unknown) => (value === undefined || value === null ? '' : String(value))
	return {
		enabled: obj.enabled === undefined ? true : !!obj.enabled,
		renderMode: str(obj.renderMode),
		textTemplate: str(obj.textTemplate),
		signatureFontSize: str(obj.signatureFontSize),
		templateFontSize: str(obj.templateFontSize),
		width: str(obj.width),
		height: str(obj.height),
	}
}

/** Fill any missing flag with the default, mirroring the backend value object. */
function normalize(raw: RawProfile | undefined): Profile {
	const base = defaultProfile()
	if (!raw) {
		return base
	}
	return {
		footer: raw.footer ?? base.footer,
		qr: raw.qr ?? base.qr,
		stamp: normalizeStamp(raw.stamp),
		auditInfo: raw.auditInfo ?? base.auditInfo,
	}
}

function stampIsDefault(stamp: StampDraft): boolean {
	return stamp.enabled
		&& !stamp.renderMode
		&& !stamp.textTemplate
		&& !stamp.signatureFontSize
		&& !stamp.templateFontSize
		&& !stamp.width
		&& !stamp.height
}

function stampEqual(a: StampDraft, b: StampDraft): boolean {
	return a.enabled === b.enabled
		&& a.renderMode === b.renderMode
		&& a.textTemplate === b.textTemplate
		&& a.signatureFontSize === b.signatureFontSize
		&& a.templateFontSize === b.templateFontSize
		&& a.width === b.width
		&& a.height === b.height
}

function profilesEqual(a: Profile, b: Profile): boolean {
	return a.footer === b.footer
		&& a.qr === b.qr
		&& a.auditInfo === b.auditInfo
		&& stampEqual(a.stamp, b.stamp)
}

const groups = ref<GroupRow[]>([])
const loadingGroups = ref(false)
const selectedGroup = ref<GroupRow | null>(null)
const saving = ref(false)
const note = ref<{ type: 'success' | 'error', message: string } | null>(null)

/** Local, editable copy of the whole appearance_profiles map. */
const working = reactive<Record<string, Profile>>({})
/** The draft flags for the currently selected group. */
const draft = reactive<Profile>(defaultProfile())

function syncWorkingFromProps() {
	Object.keys(working).forEach((key) => delete working[key])
	Object.entries(props.profiles ?? {}).forEach(([groupId, flags]) => {
		working[groupId] = normalize(flags)
	})
}

/** The persisted baseline for the selected group (custom profile or default). */
const savedProfile = computed<Profile>(() => {
	if (!selectedGroup.value) {
		return defaultProfile()
	}
	return working[selectedGroup.value.id] ?? defaultProfile()
})

const hasCustomProfile = computed(() =>
	!!selectedGroup.value && Object.prototype.hasOwnProperty.call(working, selectedGroup.value.id))

const memberCount = computed(() => selectedGroup.value?.usercount ?? 0)

const customProfileCount = computed(() => Object.keys(working).length)

const isDraftDefault = computed(() =>
	draft.footer && draft.qr && draft.auditInfo && stampIsDefault(draft.stamp))

/** Preview mode: overall document vs. the signature stamp itself. */
const previewMode = ref<'document' | 'stamp'>('document')

/** Effective stamp values for the preview: the override, else the global. */
const stampParsedGlobal = loadState<string>('libresign', 'signature_text_parsed', '')
const effectiveStampRenderMode = computed(() =>
	draft.stamp.renderMode || stampGlobals.renderMode)
const effectiveStampWidth = computed(() =>
	Number(draft.stamp.width || stampGlobals.width))
const effectiveStampHeight = computed(() =>
	Number(draft.stamp.height || stampGlobals.height))
const effectiveStampSignatureFont = computed(() =>
	Number(draft.stamp.signatureFontSize || stampGlobals.signatureFontSize))
const effectiveStampTemplateFont = computed(() =>
	Number(draft.stamp.templateFontSize || stampGlobals.templateFontSize))
const stampTemplateIsOverride = computed(() => !!draft.stamp.textTemplate)
const effectiveStampTemplateText = computed(() =>
	stampTemplateIsOverride.value ? draft.stamp.textTemplate : stampParsedGlobal)

const dirty = computed(() => !profilesEqual(draft, savedProfile.value))

/**
 * Save is allowed when there is an actual change, or when this group has
 * never been persisted yet (no entry under appearance_profiles) — the first
 * save is what creates the entry, even if it matches the default profile.
 */
const canSave = computed(() =>
	!!selectedGroup.value && (dirty.value || !hasCustomProfile.value))

const changeChips = computed(() => {
	const saved = savedProfile.value
	const chips: string[] = []

	;(Object.keys(BOOL_LABELS) as Array<keyof typeof BOOL_LABELS>).forEach((key) => {
		if (draft[key] !== saved[key]) {
			const from = saved[key] ? t('libresign', 'on') : t('libresign', 'off')
			const to = draft[key] ? t('libresign', 'on') : t('libresign', 'off')
			chips.push(`${BOOL_LABELS[key]} ${from} → ${to}`)
		}
	})

	if (draft.stamp.enabled !== saved.stamp.enabled) {
		const from = saved.stamp.enabled ? t('libresign', 'on') : t('libresign', 'off')
		const to = draft.stamp.enabled ? t('libresign', 'on') : t('libresign', 'off')
		chips.push(`${t('libresign', 'Signature stamp')} ${from} → ${to}`)
	} else if (!stampEqual(draft.stamp, saved.stamp)) {
		chips.push(t('libresign', 'Stamp settings changed'))
	}

	return chips
})

function loadDraftFromSaved() {
	const source = savedProfile.value
	draft.footer = source.footer
	draft.qr = source.qr
	draft.auditInfo = source.auditInfo
	draft.stamp = { ...source.stamp }
}

function resetToDefault() {
	const base = defaultProfile()
	draft.footer = base.footer
	draft.qr = base.qr
	draft.auditInfo = base.auditInfo
	draft.stamp = base.stamp
}

function discard() {
	loadDraftFromSaved()
}

async function searchGroup(query = '') {
	loadingGroups.value = true
	await axios.get(generateOcsUrl('cloud/groups/details'), {
		params: { search: query, limit: 20, offset: 0 },
	})
		.then(({ data }) => {
			groups.value = data.ocs.data.groups
				.sort((a: GroupRow, b: GroupRow) => a.displayname.localeCompare(b.displayname))
		})
		.catch((error) => logger.debug('Could not search groups', { error }))
	loadingGroups.value = false
}

async function save() {
	if (!selectedGroup.value || !canSave.value) {
		return
	}
	const groupName = selectedGroup.value.displayname
	saving.value = true
	note.value = null
	try {
		await confirmPassword()

		working[selectedGroup.value.id] = {
			footer: draft.footer,
			qr: draft.qr,
			auditInfo: draft.auditInfo,
			stamp: { ...draft.stamp },
		}

		await axios.post(
			generateOcsUrl('apps/libresign/api/v1/admin/appearance-profiles/config'),
			{ profiles: working },
		)

		emit('update:profiles', JSON.parse(JSON.stringify(working)))
		note.value = {
			type: 'success',
			message: t('libresign', 'Appearance profile saved for {group}', { group: groupName }),
		}

		// Return to the group selection so leaving the modal stays a conscious choice.
		selectedGroup.value = null
	} catch (error) {
		logger.error('Could not save appearance profile', { error })
		note.value = {
			type: 'error',
			message: t('libresign', 'Could not save the appearance profile. Please try again.'),
		}
	} finally {
		saving.value = false
	}
}

function onClose() {
	emit('update:open', false)
}

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		note.value = null
		syncWorkingFromProps()
		searchGroup('')
	}
})

watch(() => props.profiles, () => {
	syncWorkingFromProps()
	loadDraftFromSaved()
}, { deep: true })

watch(selectedGroup, () => {
	loadDraftFromSaved()
})
</script>

<style lang="scss" scoped>
.gp-appearance {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 28px 30px 22px;

	&__header {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__title {
		margin: 0;
		font-size: 23px;
		font-weight: 700;
		letter-spacing: -0.02em;
		color: #111827;
	}

	&__subtitle {
		margin: 0;
		font-size: 14px;
		line-height: 1.55;
		color: #6b7280;
	}

	&__body {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
		gap: 28px;
		align-items: start;
	}

	&__pane {
		display: flex;
		flex-direction: column;
		gap: 16px;
		min-width: 0;
	}

	&__pane--preview {
		position: sticky;
		top: 0;
	}

	&__preview {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__preview-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__seg {
		display: inline-flex;
		padding: 3px;
		gap: 2px;
		background: #f1f5f9;
		border-radius: 10px;
	}

	&__seg-btn {
		min-height: 0;
		padding: 5px 12px;
		border: none;
		border-radius: 8px;
		background: transparent;
		color: #64748b;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		transition: background 140ms ease, color 140ms ease;

		&--active {
			background: #fff;
			color: #16a34a;
			box-shadow: 0 1px 3px rgba(15, 23, 42, 0.1);
		}
	}

	&__note {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 12px 14px;
		border: 1px solid transparent;
		border-radius: 11px;
		font-size: 14px;
		font-weight: 500;
		line-height: 1.4;

		&--success {
			background: #ecfdf3;
			border-color: #a6f4c5;
			color: #047a45;
		}

		&--error {
			background: #fef3f2;
			border-color: #fecdca;
			color: #b42318;
		}
	}

	&__note-icon {
		display: block;
		flex: 0 0 auto;
		margin-top: 1px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__label {
		font-size: 14px;
		font-weight: 500;
		color: #475569;
	}

	&__summary {
		display: flex;
		align-items: center;
		gap: 14px;
	}

	&__badge {
		font-size: 13px;
		font-weight: 600;
		padding: 4px 12px;
		border-radius: 999px;

		&--custom {
			background: #dcfce7;
			color: #047a45;
		}

		&--default {
			background: #f1f5f9;
			color: #64748b;
		}
	}

	&__members {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 14px;
		color: #64748b;
	}

	&__members-icon {
		display: block;
		flex: 0 0 auto;
	}

	&__options-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding-bottom: 4px;
		border-bottom: 1px solid #eef0f3;
	}

	&__options-title {
		font-size: 14px;
		font-weight: 500;
		color: #475569;
	}

	&__reset {
		padding: 6px 14px;
		border: 1px solid #d7dce3;
		border-radius: 9px;
		background: #fff;
		color: #1e293b;
		font-size: 13px;
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

	&__rows {
		display: flex;
		flex-direction: column;
	}

	&__meta {
		margin: 0;
		font-size: 13px;
		color: #9ca3af;
	}

	&__foot {
		border-top: 1px solid #eef0f3;
		padding-top: 6px;
	}

	@media (max-width: 720px) {
		gap: 16px;
		padding: 22px 16px 16px;

		&__body {
			grid-template-columns: 1fr;
			gap: 20px;
		}

		&__pane--preview {
			position: static;
		}

		&__title {
			font-size: 20px;
		}
	}
}
</style>
