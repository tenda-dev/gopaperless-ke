<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="signing-card" :style="{ '--chars': chars }">
		<div class="signing-card__body">
			<div class="signing-card__hand">
				<img v-if="card?.handwriting" :src="card.handwriting" alt="">
				<span v-else class="signing-card__placeholder">{{ t('libresign', 'Signature') }}</span>
			</div>
			<strong class="signing-card__name">{{ displayName }}</strong>
			<span class="signing-card__issuer">{{ issuerText }}</span>
			<span class="signing-card__time">{{ timeText }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref, watchEffect } from 'vue'

import { signingCard, type SigningCard } from '../../services/signingCard'

defineOptions({
	name: 'SigningCardPreview',
})

/**
 * The signature box as the signed PDF draws it: handwriting on top, the name
 * in bold, the issuer, and the time (a placeholder until signing). Only the
 * signed-in user's own boxes can show their handwriting and verified name;
 * everyone else's show placeholders and the name they were invited with.
 */
const props = withDefaults(defineProps<{
	name?: string
	mine?: boolean
}>(), {
	name: '',
	mine: false,
})

const card = ref<SigningCard | null>(null)
watchEffect(() => {
	if (props.mine) {
		void signingCard().then((found) => {
			card.value = found
		})
	} else {
		card.value = null
	}
})

const displayName = computed(() => (card.value?.name ?? props.name).toUpperCase())
const issuerText = computed(() => t('libresign', 'ISSUER: {issuer}', {
	issuer: (card.value?.issuer ?? t('libresign', 'certificate issuer')).toUpperCase(),
}, undefined, { escape: false }))
const timeText = t('libresign', 'TIMESTAMP: set when signed (EAT)')
// Every line keeps its share of the name's size and must fit the width, as on the PDF.
const chars = computed(() => Math.max(8, displayName.value.length, issuerText.value.length * 0.72, timeText.length * 0.55))
</script>

<style lang="scss" scoped>
// Sized from the box itself, so the preview scales with the zoom like the PDF.
.signing-card {
	container-type: size;
	width: 100%;
	height: 100%;
	overflow: hidden;

	&__body {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: flex-end;
		height: 100%;
		padding: 6cqh 4cqw;
		box-sizing: border-box;
		font-family: Helvetica, Arial, sans-serif;
		text-align: center;
		line-height: 1.2;
	}

	&__hand {
		flex: 1 1 auto;
		min-height: 0;
		width: 100%;
		display: flex;
		align-items: center;
		justify-content: center;

		img {
			max-width: 100%;
			max-height: 100%;
			object-fit: contain;
		}
	}

	&__placeholder {
		font-size: 9cqh;
		font-style: italic;
		color: #8a94a6;
	}

	&__name,
	&__issuer,
	&__time {
		max-width: 100%;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	// The name sets the scale; it shrinks for whichever line is longest.
	// Unregistered, so the cq units resolve in the lines, against this box.
	--size: min(12cqh, calc(92cqw / (var(--chars) * 0.72)));

	&__name {
		font-size: var(--size);
		font-weight: 700;
		color: #0b1120;
	}

	&__issuer {
		font-size: calc(var(--size) * 0.72);
		color: #40444f;
	}

	&__time {
		font-size: calc(var(--size) * 0.62);
		color: #666b78;
	}
}
</style>
