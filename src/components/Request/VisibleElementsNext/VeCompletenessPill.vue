<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
  Quiet completeness pill — "N of M placed". Soft green when complete, soft amber
  when short. Shared by the desktop sidebar and the mobile sheet.
-->
<template>
	<span class="ve-pill" :class="complete ? 've-pill--ok' : 've-pill--warn'" :aria-label="ariaLabel">
		<NcIconSvgWrapper :path="complete ? mdiCheckCircle : mdiAlertCircleOutline" :size="13" aria-hidden="true" />
		{{ t('libresign', '{placed} of {total} placed', { placed, total }) }}
	</span>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiAlertCircleOutline, mdiCheckCircle } from '@mdi/js'

defineOptions({
	name: 'VeCompletenessPill',
})

const props = defineProps<{
	placed: number
	total: number
}>()

const complete = computed(() => props.total > 0 && props.placed >= props.total)
const ariaLabel = computed(() => t('libresign', '{placed} of {total} signers placed', { placed: props.placed, total: props.total }))
</script>

<style lang="scss" scoped>
.ve-pill {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
	font-size: 12px;
	font-weight: 600;
	line-height: 1;
	padding: 0 8px;
	border-radius: 999px;

	&--ok {
		color: #067a45;
		background-color: rgba(4, 213, 109, 0.14);
	}

	&--warn {
		color: #8a5a12;
		background-color: rgba(186, 117, 23, 0.16);
	}
}
</style>
