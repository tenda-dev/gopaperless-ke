/*
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, reactive, watch, type Ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import type { StampDraft, StampGlobals } from '../SignatureStampConfig.vue'

export type Profile = { footer: boolean; qr: boolean; stamp: StampDraft; auditInfo: boolean }
export type RawProfile = { footer?: boolean; qr?: boolean; stamp?: boolean | Record<string, unknown>; auditInfo?: boolean }
export type GroupRow = { id: string; displayname: string; usercount?: number }

export const stampGlobals: StampGlobals = {
	renderMode: loadState<string>('libresign', 'signature_render_mode', 'GRAPHIC_AND_DESCRIPTION'),
	textTemplate: loadState<string>('libresign', 'signature_text_template', ''),
	signatureFontSize: loadState<number>('libresign', 'signature_font_size', 10),
	templateFontSize: loadState<number>('libresign', 'template_font_size', 10),
	width: loadState<number>('libresign', 'signature_width', 350),
	height: loadState<number>('libresign', 'signature_height', 100),
}

const BOOL_LABELS = { footer: t('libresign', 'Footer'), qr: t('libresign', 'QR code'), auditInfo: t('libresign', 'Audit info') } as const

function defaultStamp(): StampDraft { return { enabled: true, renderMode: '', textTemplate: '', signatureFontSize: '', templateFontSize: '', width: '', height: '' } }
function defaultProfile(): Profile { return { footer: true, qr: true, stamp: defaultStamp(), auditInfo: true } }
function normalizeStamp(raw: boolean | Record<string, unknown> | undefined): StampDraft {
	if (typeof raw === 'boolean') return { ...defaultStamp(), enabled: raw }
	const obj = raw ?? {}
	const str = (value: unknown) => (value == null ? '' : String(value))
	return { enabled: obj.enabled === undefined ? true : !!obj.enabled, renderMode: str(obj.renderMode), textTemplate: str(obj.textTemplate), signatureFontSize: str(obj.signatureFontSize), templateFontSize: str(obj.templateFontSize), width: str(obj.width), height: str(obj.height) }
}
function normalize(raw: RawProfile | undefined): Profile {
	const base = defaultProfile()
	if (!raw) return base
	return { footer: raw.footer ?? base.footer, qr: raw.qr ?? base.qr, stamp: normalizeStamp(raw.stamp), auditInfo: raw.auditInfo ?? base.auditInfo }
}
export function stampIsDefault(stamp: StampDraft): boolean { return stamp.enabled && !stamp.renderMode && !stamp.textTemplate && !stamp.signatureFontSize && !stamp.templateFontSize && !stamp.width && !stamp.height }
function stampEqual(a: StampDraft, b: StampDraft): boolean { return a.enabled === b.enabled && a.renderMode === b.renderMode && a.textTemplate === b.textTemplate && a.signatureFontSize === b.signatureFontSize && a.templateFontSize === b.templateFontSize && a.width === b.width && a.height === b.height }
function profilesEqual(a: Profile, b: Profile): boolean { return a.footer === b.footer && a.qr === b.qr && a.auditInfo === b.auditInfo && stampEqual(a.stamp, b.stamp) }
export function useSignatureProfileForm(props: { profiles: Record<string, RawProfile> }, selectedGroup: Ref<GroupRow | null>) {
	const working = reactive<Record<string, Profile>>({})
	const draft = reactive<Profile>(defaultProfile())
	function syncWorkingFromProps() {
		Object.keys(working).forEach((key) => delete working[key])
		Object.entries(props.profiles ?? {}).forEach(([groupId, flags]) => { working[groupId] = normalize(flags) })
	}
	const savedProfile = computed<Profile>(() => selectedGroup.value ? (working[selectedGroup.value.id] ?? defaultProfile()) : defaultProfile())
	const hasCustomProfile = computed(() => !!selectedGroup.value && Object.prototype.hasOwnProperty.call(working, selectedGroup.value.id))
	const memberCount = computed(() => selectedGroup.value?.usercount ?? 0)
	const customProfileCount = computed(() => Object.keys(working).length)
	const dirty = computed(() => !profilesEqual(draft, savedProfile.value))
	const canSave = computed(() => !!selectedGroup.value && (dirty.value || !hasCustomProfile.value))
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
	function discard() { loadDraftFromSaved() }
	watch(() => props.profiles, () => { syncWorkingFromProps(); loadDraftFromSaved() }, { deep: true })
	watch(selectedGroup, loadDraftFromSaved)
	syncWorkingFromProps()
	return { working, draft, stampGlobals, hasCustomProfile, memberCount, customProfileCount, dirty, canSave, changeChips, resetToDefault, discard }
}
