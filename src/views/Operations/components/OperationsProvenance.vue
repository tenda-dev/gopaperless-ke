<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="ops-prov"
		:class="confirmed ? 'ops-prov--ok' : 'ops-prov--inst'"
		:title="confirmed ? t('libresign', 'Calculated from recorded data') : t('libresign', 'Estimated or unavailable; additional tracking needed')"
		role="img"
		:aria-label="confirmed ? t('libresign', 'Calculated from recorded data') : t('libresign', 'Estimated or unavailable; additional tracking needed')" />
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

defineOptions({ name: 'OperationsProvenance' })

withDefaults(defineProps<{ confirmed?: boolean }>(), { confirmed: true })
</script>

<style scoped lang="scss">
.ops-prov {
	display: inline-block;
	flex: none;
	width: 8px;
	height: 8px;
	border-radius: 50%;

	&--ok {
		background: var(--ops-green);
	}

	&--inst {
		background: var(--ops-warn);
		box-shadow: inset 0 0 0 2px var(--ops-surface), 0 0 0 1px var(--ops-warn);
	}
}
</style>
