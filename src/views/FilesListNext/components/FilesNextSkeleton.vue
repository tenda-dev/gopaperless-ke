<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  - Loading skeleton for the Files list — shimmer placeholder rows shown on the
  - first load in place of a spinner. Purely presentational.
-->
<template>
	<div class="files-next-skeleton" aria-hidden="true">
		<template v-if="!mobile">
			<div v-for="i in 9" :key="i" class="sk-row">
				<span class="skel sk-check" />
				<span class="skel sk-thumb" />
				<span class="sk-name">
					<span class="skel sk-line sk-line--lg" />
					<span class="skel sk-line sk-line--sm" />
				</span>
				<span class="skel sk-pill" />
				<span class="skel sk-date" />
			</div>
		</template>
		<template v-else>
			<div v-for="i in 8" :key="i" class="sk-mrow">
				<span class="skel sk-mthumb" />
				<span class="sk-mmain">
					<span class="skel sk-line sk-line--lg" />
					<span class="skel sk-line sk-line--sm" />
					<span class="skel sk-pill sk-pill--sm" />
				</span>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
defineOptions({ name: 'FilesNextSkeleton' })

defineProps<{ mobile?: boolean }>()
</script>

<style scoped lang="scss">
.files-next-skeleton {
	padding: 2px 0;
}

.skel {
	display: block;
	border-radius: 6px;
	background: var(--color-background-dark);
	background-image: linear-gradient(90deg,
			rgba(255, 255, 255, 0) 0%,
			rgba(255, 255, 255, 0.45) 50%,
			rgba(255, 255, 255, 0) 100%);
	background-size: 200% 100%;
	background-repeat: no-repeat;
	animation: fn-skeleton-shimmer 1.4s ease-in-out infinite;
}

.sk-row {
	display: flex;
	align-items: center;
	gap: 12px;
	height: 64px;
	padding: 0 12px;
	border-bottom: 1px solid var(--color-border);
}

.sk-check { flex: 0 0 auto; width: 18px; height: 18px; border-radius: 5px; }
.sk-thumb { flex: 0 0 auto; width: 40px; height: 50px; border-radius: 7px; }
.sk-name { flex: 1 1 auto; display: flex; flex-direction: column; gap: 8px; }
.sk-line { height: 11px; }
.sk-line--lg { width: 40%; }
.sk-line--sm { width: 22%; height: 9px; }
.sk-pill { flex: 0 0 auto; width: 96px; height: 22px; border-radius: 12px; }
.sk-pill--sm { width: 74px; height: 18px; }
.sk-date { flex: 0 0 auto; width: 66px; height: 12px; }

.sk-mrow {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 4px;
	border-bottom: 1px solid var(--color-border);
}
.sk-mthumb { flex: 0 0 auto; width: 48px; height: 60px; border-radius: 8px; }
.sk-mmain { flex: 1 1 auto; display: flex; flex-direction: column; gap: 9px; }

@keyframes fn-skeleton-shimmer {
	0% { background-position: 150% 0; }
	100% { background-position: -50% 0; }
}
</style>
