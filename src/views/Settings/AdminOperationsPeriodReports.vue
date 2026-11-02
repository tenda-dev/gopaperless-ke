<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('libresign', 'Operations email reports')"
		:description="t('libresign', 'Email a daily, weekly or monthly summary of the Operations analytics. It is sent to the members of the groups selected under “Operations dashboard” (administrators only if they belong to those groups). Disabled by default and independent of the dashboard switch. Delivery depends on the server’s background-job schedule. Nothing is sent for periods that fell due before you enabled the report or changed its schedule.')">
		<NcCheckboxRadioSwitch type="switch"
			v-model="enabled"
			:disabled="saving">
			{{ t('libresign', 'Send Operations email reports') }}
		</NcCheckboxRadioSwitch>

		<div v-if="enabled" class="period-reports__fields">
			<div class="period-reports__field">
				<label class="period-reports__label" :for="ids.frequency">{{ t('libresign', 'Frequency') }}</label>
				<NcSelect :input-id="ids.frequency"
					v-model="frequencyOption"
					label="label"
					:clearable="false"
					:searchable="false"
					:disabled="saving"
					:options="frequencyOptions" />
			</div>

			<div v-if="frequency === 'weekly'" class="period-reports__field">
				<label class="period-reports__label" :for="ids.weekday">{{ t('libresign', 'Day of the week') }}</label>
				<NcSelect :input-id="ids.weekday"
					v-model="weekdayOption"
					label="label"
					:clearable="false"
					:searchable="false"
					:disabled="saving"
					:options="weekdayOptions" />
			</div>

			<div v-else-if="frequency === 'monthly'" class="period-reports__field">
				<label class="period-reports__label" :for="ids.dayOfMonth">{{ t('libresign', 'Day of the month (1–28)') }}</label>
				<NcSelect :input-id="ids.dayOfMonth"
					v-model="dayOfMonthOption"
					label="label"
					:clearable="false"
					:searchable="false"
					:disabled="saving"
					:options="dayOfMonthOptions" />
			</div>

			<div class="period-reports__field">
				<label class="period-reports__label" :for="ids.time">{{ t('libresign', 'Time of day') }}</label>
				<input :id="ids.time"
					v-model="time"
					class="period-reports__time"
					type="time"
					required
					:disabled="saving">
			</div>

			<div class="period-reports__field">
				<label class="period-reports__label" :for="ids.timezone">{{ t('libresign', 'Timezone') }}</label>
				<NcSelect :input-id="ids.timezone"
					v-model="timezone"
					:clearable="false"
					:disabled="saving"
					:options="timezoneOptions" />
			</div>
		</div>

		<p v-if="nextSummary" class="period-reports__next">
			{{ nextSummary }}
		</p>

		<div v-if="isDirty" class="period-reports__unsaved" role="status">
			<div class="period-reports__unsaved-summary">
				<strong>{{ t('libresign', 'Unsaved changes') }}</strong>
				<ul>
					<li v-for="change in changes" :key="change">
						{{ change }}
					</li>
				</ul>
			</div>
			<div class="period-reports__unsaved-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="discard">
					{{ t('libresign', 'Discard') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !canSave" @click="save">
					{{ t('libresign', 'Save') }}
				</NcButton>
			</div>
		</div>
		<p v-if="errorMessage" class="period-reports__error" role="alert">
			{{ errorMessage }}
		</p>

		<div v-if="saved.enabled" class="period-reports__test">
			<label class="period-reports__label" :for="ids.testEmail">{{ t('libresign', 'Test report email') }}</label>
			<div class="period-reports__test-row">
				<input :id="ids.testEmail"
					v-model="testEmail"
					class="period-reports__time period-reports__test-input"
					type="email"
					autocomplete="off"
					:placeholder="t('libresign', 'Your account email')"
					:disabled="testing">
				<NcButton variant="secondary" :disabled="testing || !testEmailIsValid" @click="sendTest">
					{{ testing ? t('libresign', 'Sending…') : t('libresign', 'Send test email') }}
				</NcButton>
			</div>
			<p class="period-reports__test-hint">
				{{ t('libresign', 'Sends one report for the latest completed period, using the saved schedule, to this address only (blank: your account email). The recipients are not contacted.') }}
			</p>
			<p v-if="testMessage" class="period-reports__test-result" role="status">
				{{ testMessage }}
			</p>
			<p v-if="testError" class="period-reports__error" role="alert">
				{{ testError }}
			</p>
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import logger from '../../logger.js'
import type {
	AdminInitialState,
	OperationsEmailFrequency,
	OperationsEmailNext,
	OperationsEmailSchedule,
} from '../../types'

import '@nextcloud/password-confirmation/style.css'

defineOptions({
	name: 'AdminOperationsPeriodReportsSettings',
})

type Option = { id: number | string, label: string }

const DEFAULT_SCHEDULE: OperationsEmailSchedule = {
	frequency: 'weekly',
	weekday: 1,
	dayOfMonth: 1,
	time: '09:00',
	timezone: 'Africa/Nairobi',
}

const ids = {
	frequency: 'operations-email-frequency',
	weekday: 'operations-email-weekday',
	dayOfMonth: 'operations-email-day-of-month',
	time: 'operations-email-time',
	timezone: 'operations-email-timezone',
	testEmail: 'operations-email-test-address',
}

const initialEnabled = loadState<AdminInitialState['operations_email_reports_admin_enabled']>('libresign', 'operations_email_reports_admin_enabled', false)
const initialSchedule = {
	...DEFAULT_SCHEDULE,
	...loadState<AdminInitialState['operations_email_reports_admin_schedule']>('libresign', 'operations_email_reports_admin_schedule', DEFAULT_SCHEDULE),
}
const initialNext = loadState<AdminInitialState['operations_email_reports_admin_next']>('libresign', 'operations_email_reports_admin_next', null)

// Last saved state; the draft refs below are what the admin is editing.
const saved = ref<{ enabled: boolean, schedule: OperationsEmailSchedule }>({
	enabled: initialEnabled,
	schedule: { ...initialSchedule },
})
const next = ref<OperationsEmailNext | null>(initialNext)

const enabled = ref(initialEnabled)
const frequency = ref<OperationsEmailFrequency>(initialSchedule.frequency)
const weekday = ref(initialSchedule.weekday)
const dayOfMonth = ref(initialSchedule.dayOfMonth)
const time = ref(initialSchedule.time)
const timezone = ref(initialSchedule.timezone)
const saving = ref(false)
const errorMessage = ref('')
const testing = ref(false)
const testEmail = ref('')
const testEmailIsValid = computed(() => testEmail.value.trim() === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testEmail.value.trim()))
const testMessage = ref('')
const testError = ref('')

const frequencyOptions = computed<Option[]>(() => [
	{ id: 'daily', label: t('libresign', 'Daily') },
	{ id: 'weekly', label: t('libresign', 'Weekly') },
	{ id: 'monthly', label: t('libresign', 'Monthly') },
])
const weekdayOptions = computed<Option[]>(() => [
	{ id: 1, label: t('libresign', 'Monday') },
	{ id: 2, label: t('libresign', 'Tuesday') },
	{ id: 3, label: t('libresign', 'Wednesday') },
	{ id: 4, label: t('libresign', 'Thursday') },
	{ id: 5, label: t('libresign', 'Friday') },
	{ id: 6, label: t('libresign', 'Saturday') },
	{ id: 7, label: t('libresign', 'Sunday') },
])
const dayOfMonthOptions = computed<Option[]>(() => Array.from({ length: 28 }, (_, i) => ({ id: i + 1, label: String(i + 1) })))

const timezoneOptions = computed<string[]>(() => {
	let zones: string[] = []
	try {
		zones = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? []
	} catch (error) {
		logger.debug('Could not list timezones', { error })
	}
	return Array.from(new Set(['UTC', DEFAULT_SCHEDULE.timezone, saved.value.schedule.timezone, timezone.value, ...zones])).sort()
})

function optionOf(options: Option[], id: number | string): Option {
	return options.find((option) => option.id === id) ?? options[0]
}

const frequencyOption = computed<Option>({
	get: () => optionOf(frequencyOptions.value, frequency.value),
	set: (option) => { frequency.value = option.id as OperationsEmailFrequency },
})
const weekdayOption = computed<Option>({
	get: () => optionOf(weekdayOptions.value, weekday.value),
	set: (option) => { weekday.value = Number(option.id) },
})
const dayOfMonthOption = computed<Option>({
	get: () => optionOf(dayOfMonthOptions.value, dayOfMonth.value),
	set: (option) => { dayOfMonth.value = Number(option.id) },
})

const timeIsValid = computed(() => /^([01]\d|2[0-3]):[0-5]\d$/.test(time.value))
const canSave = computed(() => !enabled.value || timeIsValid.value)

function currentSchedule(): OperationsEmailSchedule {
	return {
		frequency: frequency.value,
		weekday: weekday.value,
		dayOfMonth: dayOfMonth.value,
		time: time.value,
		timezone: timezone.value,
	}
}

// Only the fields that apply to the chosen frequency count as a change.
function relevantFields(schedule: OperationsEmailSchedule): Array<[string, string]> {
	const fields: Array<[string, string]> = [['frequency', schedule.frequency]]
	if (schedule.frequency === 'weekly') {
		fields.push(['weekday', String(schedule.weekday)])
	}
	if (schedule.frequency === 'monthly') {
		fields.push(['dayOfMonth', String(schedule.dayOfMonth)])
	}
	fields.push(['time', schedule.time], ['timezone', schedule.timezone])
	return fields
}

function describe(schedule: OperationsEmailSchedule): string {
	const when = schedule.frequency === 'weekly'
		? weekdayOptions.value.find((o) => o.id === schedule.weekday)?.label ?? ''
		: schedule.frequency === 'monthly'
			? t('libresign', 'day {day}', { day: schedule.dayOfMonth })
			: ''
	const label = frequencyOptions.value.find((o) => o.id === schedule.frequency)?.label ?? schedule.frequency
	return [label, when, schedule.time, schedule.timezone].filter(Boolean).join(', ')
}

const scheduleChanged = computed(() => {
	const a = relevantFields(saved.value.schedule)
	const b = relevantFields(currentSchedule())
	return a.length !== b.length || a.some(([key, value], index) => key !== b[index][0] || value !== b[index][1])
})

const isDirty = computed(() => enabled.value !== saved.value.enabled || (enabled.value && scheduleChanged.value))

const changes = computed<string[]>(() => {
	const list: string[] = []
	if (enabled.value !== saved.value.enabled) {
		list.push(enabled.value
			? t('libresign', 'Email reports: off → on')
			: t('libresign', 'Email reports: on → off'))
	}
	if (enabled.value && scheduleChanged.value) {
		list.push(t('libresign', 'Schedule: {from} → {to}', {
			from: describe(saved.value.schedule),
			to: describe(currentSchedule()),
		}))
	}
	return list
})

const nextSummary = computed(() => {
	if (!saved.value.enabled || next.value === null) {
		return ''
	}
	let when = next.value.dueAt
	try {
		when = new Intl.DateTimeFormat(undefined, {
			weekday: 'long',
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			timeZone: next.value.timezone,
			timeZoneName: 'short',
		}).format(new Date(next.value.dueAt))
	} catch (error) {
		logger.debug('Could not format the next report time', { error })
	}
	return t('libresign', 'Next report: {when}, covering {from} – {to}', {
		when,
		from: next.value.periodFrom,
		to: next.value.periodTo,
	})
})

function discard() {
	enabled.value = saved.value.enabled
	frequency.value = saved.value.schedule.frequency
	weekday.value = saved.value.schedule.weekday
	dayOfMonth.value = saved.value.schedule.dayOfMonth
	time.value = saved.value.schedule.time
	timezone.value = saved.value.schedule.timezone
	errorMessage.value = ''
}

async function save() {
	if (!canSave.value) {
		return
	}
	saving.value = true
	errorMessage.value = ''
	try {
		await confirmPassword()
		const { data } = await axios.post(generateOcsUrl('apps/libresign/api/v1/admin/operations-email-reports/config'), {
			enabled: enabled.value,
			schedule: currentSchedule(),
		})
		saved.value = { enabled: enabled.value, schedule: currentSchedule() }
		next.value = data?.ocs?.data?.next ?? null
	} catch (error) {
		logger.debug('Could not save the Operations email report settings', { error })
		errorMessage.value = t('libresign', 'Could not save the report settings.')
	}
	saving.value = false
}

async function sendTest() {
	testing.value = true
	testMessage.value = ''
	testError.value = ''
	try {
		await axios.post(generateOcsUrl('apps/libresign/api/v1/admin/operations-email-reports/test'), {
			email: testEmail.value.trim(),
		})
		testMessage.value = t('libresign', 'Test email sent.')
	} catch (error) {
		logger.debug('Could not send the Operations test email', { error })
		const serverMessage = (error as { response?: { data?: { ocs?: { data?: { error?: string } } } } })?.response?.data?.ocs?.data?.error
		testError.value = serverMessage || t('libresign', 'The test email could not be sent.')
	}
	testing.value = false
}

defineExpose({
	enabled,
	frequency,
	weekday,
	dayOfMonth,
	time,
	timezone,
	saving,
	testing,
	testEmail,
	testEmailIsValid,
	testMessage,
	testError,
	sendTest,
	isDirty,
	changes,
	nextSummary,
	save,
	discard,
})
</script>

<style scoped lang="scss">
.period-reports {
	&__fields {
		display: flex;
		flex-wrap: wrap;
		gap: 12px 24px;
		margin-top: 12px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		min-width: 200px;
	}

	&__label {
		margin-bottom: 4px;
		color: var(--color-text-maxcontrast);
	}

	&__time {
		min-height: var(--default-clickable-area);
		padding: 0 12px;
		border: 2px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-element);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__next {
		margin-top: 16px;
		color: var(--color-text-maxcontrast);
	}

	&__unsaved {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-top: 16px;
		padding: 12px 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);

		ul {
			margin: 4px 0 0 16px;
			list-style: disc;
			color: var(--color-text-maxcontrast);
		}
	}

	&__unsaved-actions {
		display: flex;
		gap: 8px;
	}

	&__test {
		margin-top: 20px;
	}

	&__test-row {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;
	}

	&__test-input {
		min-width: 260px;
	}

	&__test-hint {
		margin-top: 6px;
		color: var(--color-text-maxcontrast);
	}

	&__test-result {
		margin-top: 8px;
		color: var(--color-success-text);
	}

	&__error {
		margin-top: 8px;
		color: var(--color-error-text);
	}
}
</style>
