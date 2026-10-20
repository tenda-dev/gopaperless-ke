<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="pw">
		<header class="pw__head">
			<h1 class="pw__title">{{ t('libresign', 'Prepare a document to sign') }}</h1>
			<p class="pw__sub">{{ t('libresign', 'No account needed. Your draft is kept privately and never sent until you choose to.') }}</p>
		</header>

		<!-- loading -->
		<div v-if="phase === 'loading'" class="pw__state" aria-live="polite">
			<span class="pw__spinner" /> {{ t('libresign', 'Loading…') }}
		</div>

		<!-- expired / invalid -->
		<div v-else-if="phase === 'expired'" class="pw__state pw__state--warn">
			<p>{{ t('libresign', 'This workflow has expired or is no longer valid.') }}</p>
			<button class="pw__btn pw__btn--primary" @click="start">{{ t('libresign', 'Start a new one') }}</button>
		</div>

		<!-- hard error -->
		<div v-else-if="phase === 'error'" class="pw__state pw__state--warn">
			<p>{{ errorMessage || t('libresign', 'Something went wrong.') }}</p>
			<button class="pw__btn" @click="resume">{{ t('libresign', 'Try again') }}</button>
		</div>

		<!-- idle: start -->
		<div v-else-if="phase === 'idle'" class="pw__state">
			<button class="pw__btn pw__btn--primary" @click="start">{{ t('libresign', 'Start') }}</button>
		</div>

		<!-- ready -->
		<div v-else-if="phase === 'ready'" class="pw__body">
			<section v-if="!hasDocument" class="pw__card">
				<h2 class="pw__h2">{{ t('libresign', 'Upload your document') }}</h2>
				<input ref="fileInput" type="file" accept="application/pdf" class="pw__file" @change="onPick" />
				<p v-if="uploadError" class="pw__err">{{ uploadError }}</p>
				<div v-if="uploading" class="pw__progress"><span :style="{ width: progress + '%' }" /></div>
			</section>

			<section v-else class="pw__card">
				<p class="pw__ok">✓ {{ t('libresign', 'Document uploaded (draft).') }}</p>

				<h2 class="pw__h2">{{ t('libresign', 'Who needs to sign?') }}</h2>
				<div v-for="(s, i) in signers" :key="i" class="pw__signer">
					<input
						v-model="s.displayName"
						class="pw__input"
						type="text"
						:placeholder="t('libresign', 'Name')" />
					<input
						v-model="s.email"
						class="pw__input"
						type="email"
						:placeholder="t('libresign', 'Email')" />
					<button class="pw__btn pw__btn--ghost" :aria-label="t('libresign', 'Remove')" @click="removeSigner(i)">×</button>
				</div>
				<button class="pw__btn pw__btn--ghost" @click="addSigner">+ {{ t('libresign', 'Add signer') }}</button>

				<p v-if="prepError" class="pw__err">{{ prepError }}</p>
				<div class="pw__actions">
					<button class="pw__btn pw__btn--primary" :disabled="savingSigners" @click="onSaveSigners">
						{{ savingSigners ? t('libresign', 'Saving…') : t('libresign', 'Save signers') }}
					</button>
				</div>
				<p class="pw__note">{{ t('libresign', 'Saving keeps everything as a draft. Nothing is sent or signed in this step.') }}</p>
			</section>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'

import { usePublicWorkflow } from '@/store/publicWorkflow'

defineOptions({ name: 'PublicWorkflow' })

const { phase, hasDocument, errorMessage, resume, start, upload, saveSigners, signers } = usePublicWorkflow()

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const progress = ref(0)
const uploadError = ref('')

// Signer rows are owned by the store so they survive reloads (hydrated from
// the server's DRAFT document on resume / after each save).
const savingSigners = ref(false)
const prepError = ref('')

onMounted(resume)

async function onPick(ev: Event): Promise<void> {
	const file = (ev.target as HTMLInputElement).files?.[0]
	if (!file) {
		return
	}
	uploadError.value = ''
	uploading.value = true
	progress.value = 0
	try {
		await upload(file, (e) => {
			if (e.total) {
				progress.value = Math.round((e.loaded / e.total) * 100)
			}
		})
	} catch {
		uploadError.value = errorMessage.value || t('libresign', 'Upload failed')
	} finally {
		uploading.value = false
		if (fileInput.value) {
			fileInput.value.value = ''
		}
	}
}

function addSigner(): void {
	signers.value.push({ displayName: '', email: '' })
}

function removeSigner(i: number): void {
	signers.value.splice(i, 1)
	if (signers.value.length === 0) {
		addSigner()
	}
}

async function onSaveSigners(): Promise<void> {
	prepError.value = ''
	// Canonical signer shape expected by the save pipeline: identifyMethods.
	const payload = signers.value
		.filter(s => s.email.trim())
		.map(s => ({
			displayName: s.displayName.trim(),
			identifyMethods: [{ method: 'email', value: s.email.trim() }],
			notify: 0,
		}))
	if (payload.length === 0) {
		prepError.value = t('libresign', 'Add at least one signer email.')
		return
	}
	savingSigners.value = true
	try {
		await saveSigners(payload)
	} catch {
		prepError.value = errorMessage.value || t('libresign', 'Could not save signers')
	} finally {
		savingSigners.value = false
	}
}
</script>

<style scoped lang="scss">
.pw {
	max-width: 640px;
	margin: 0 auto;
	padding: 2rem 16px 4rem;
	color: var(--color-main-text);
}

.pw__title { font-size: 1.6rem; font-weight: 700; margin: 0 0 .25rem; }

.pw__sub { color: var(--color-text-maxcontrast); margin: 0 0 1.5rem; }

.pw__state { display: flex; flex-direction: column; gap: .75rem; align-items: flex-start; }

.pw__state--warn { color: var(--color-warning-text, var(--color-main-text)); }

.pw__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 1.25rem;
	background: var(--color-main-background);
	margin-bottom: 1rem;
}

.pw__h2 { font-size: 1.05rem; font-weight: 600; margin: 0 0 .75rem; }

.pw__ok { color: var(--color-success, #2d7d46); margin: 0 0 1.25rem; }

.pw__file { display: block; }

.pw__signer { display: flex; gap: .5rem; margin-bottom: .5rem; align-items: center; }

.pw__input {
	flex: 1;
	padding: .5rem .6rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.pw__actions { margin-top: 1rem; }

.pw__note { color: var(--color-text-maxcontrast); font-size: .85rem; margin-top: .75rem; }

.pw__err { color: var(--color-error, #c0392b); font-size: .9rem; }

.pw__btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius, 8px);
	padding: .5rem .9rem;
	cursor: pointer;
}

.pw__btn--primary {
	background: var(--color-primary-element, #2d7d46);
	color: var(--color-primary-element-text, #fff);
	border-color: transparent;
}

.pw__btn--ghost { border-color: transparent; background: transparent; }

.pw__btn:disabled { opacity: .6; cursor: default; }

.pw__progress { height: 4px; background: var(--color-border); border-radius: 2px; margin-top: .75rem; overflow: hidden; }

.pw__progress > span { display: block; height: 100%; background: var(--color-primary-element, #2d7d46); transition: width .2s; }

.pw__spinner {
	width: 16px; height: 16px; border-radius: 50%;
	border: 2px solid var(--color-border); border-top-color: var(--color-primary-element, #2d7d46);
	display: inline-block; animation: pw-spin .8s linear infinite; vertical-align: middle;
}
@keyframes pw-spin { to { transform: rotate(360deg); } }
</style>
