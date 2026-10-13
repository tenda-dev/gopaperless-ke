<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="age-card user-search">
		<div class="user-search__field-label">{{ t('libresign', 'Find a user by email') }}</div>
		<div class="user-search__box" :class="{ 'user-search__box--focus': focused }">
			<NcIconSvgWrapper class="user-search__icon" :path="mdiMagnify" :size="18" />
			<input
				ref="inputRef"
				v-model="query"
				type="search"
				class="user-search__input"
				:placeholder="t('libresign', 'name@example.com')"
				:aria-label="t('libresign', 'Search users by email')"
				autocomplete="off"
				@focus="focused = true"
				@blur="focused = false"
				@keydown.enter.prevent="runSearch">
			<NcLoadingIcon v-if="loading" :size="18" class="user-search__spinner" />
			<button v-else-if="query" type="button" class="user-search__clear" :aria-label="t('libresign', 'Clear search')" @click="clearQuery">
				<NcIconSvgWrapper :path="mdiClose" :size="15" />
			</button>
		</div>
		<p class="user-search__hint">{{ t('libresign', 'Type at least 3 characters. Admin-only search.') }}</p>

		<div v-if="error" class="user-search__state user-search__state--error">{{ error }}</div>

		<ul v-else-if="results.length" class="user-search__results">
			<li v-for="u in results" :key="u.uid">
				<button type="button" class="user-search__result" @click="select(u)">
					<span class="user-search__avatar">{{ initials(u) }}</span>
					<span class="user-search__who">
						<span class="user-search__name">{{ u.displayName || u.uid }}</span>
						<span class="user-search__email">{{ u.email || t('libresign', 'No email on file') }}</span>
					</span>
					<span class="user-search__uid">{{ u.uid }}</span>
				</button>
			</li>
		</ul>

		<div v-else-if="searched && !loading" class="user-search__state">
			{{ t('libresign', 'No accounts match “{query}”.', { query: lastQuery }) }}
		</div>
	</section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiMagnify, mdiClose } from '@mdi/js'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

import { useAdminEntitlements, ocsError, type AdminUser } from './useAdminEntitlements'

const props = defineProps<{ autofocus?: boolean }>()
const emit = defineEmits<{(e: 'select', user: AdminUser): void }>()

const { searchUsers } = useAdminEntitlements()

const inputRef = ref<HTMLInputElement | null>(null)
const query = ref('')
const lastQuery = ref('')
const results = ref<AdminUser[]>([])
const loading = ref(false)
const error = ref('')
const searched = ref(false)
const focused = ref(false)

let debounce: ReturnType<typeof setTimeout> | undefined

watch(query, (value) => {
	if (debounce) { clearTimeout(debounce) }
	error.value = ''
	if (value.trim().length < 3) {
		results.value = []
		searched.value = false
		return
	}
	debounce = setTimeout(runSearch, 350)
})

async function runSearch() {
	if (debounce) { clearTimeout(debounce) }
	const term = query.value.trim()
	if (term.length < 3) {
		results.value = []
		searched.value = false
		return
	}
	loading.value = true
	error.value = ''
	try {
		results.value = await searchUsers(term)
		lastQuery.value = term
		searched.value = true
	} catch (err) {
		error.value = ocsError(err, t('libresign', 'Search failed. Please try again.'))
		results.value = []
	} finally {
		loading.value = false
	}
}

function clearQuery() {
	query.value = ''
	results.value = []
	searched.value = false
	inputRef.value?.focus()
}

function select(user: AdminUser) { emit('select', user) }

function initials(u: AdminUser): string {
	const base = (u.displayName || u.uid || '?').trim()
	const parts = base.split(/\s+/)
	const first = parts[0]?.[0] ?? ''
	const second = parts.length > 1 ? parts[parts.length - 1][0] : ''
	return (first + second).toUpperCase() || '?'
}

onMounted(() => { if (props.autofocus) { inputRef.value?.focus() } })
</script>

<style scoped lang="scss">
@use './admin-grants.scss';

.user-search {
	&__field-label {
		font-size: 13px;
		font-weight: 600;
		color: var(--age-fg-muted);
		margin-bottom: 8px;
	}

	&__box {
		display: flex;
		align-items: center;
		gap: 9px;
		padding: 0 12px;
		border: 1px solid var(--age-border-strong);
		border-radius: var(--age-radius-sm);
		background: var(--age-surface);
		transition: border-color 0.15s ease, box-shadow 0.15s ease;

		&--focus {
			border-color: var(--age-primary);
			box-shadow: 0 0 0 2px var(--age-focus-ring);
		}
	}

	&__icon { color: var(--age-fg-muted); flex: none; }
	&__spinner { flex: none; }

	&__input {
		flex: 1 1 auto;
		min-width: 0;
		height: 40px;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: none;
		background: transparent;
		color: var(--age-fg);
		font-size: 14px;

		&:focus-visible { outline: none; }
		&::placeholder { color: var(--age-fg-faint); }
		&::-webkit-search-cancel-button { display: none; }
	}

	&__clear {
		display: inline-grid;
		place-items: center;
		width: 24px;
		height: 24px;
		border: none;
		border-radius: 50%;
		background: var(--age-surface-2);
		color: var(--age-fg-muted);
		cursor: pointer;
		flex: none;

		&:hover { color: var(--age-fg); }
		&:focus-visible { outline: 2px solid var(--age-focus); outline-offset: 1px; }
	}

	&__hint {
		margin: 8px 0 0;
		font-size: 12px;
		color: var(--age-fg-faint);
	}

	&__state {
		padding: 16px 2px 2px;
		font-size: 13px;
		color: var(--age-fg-muted);

		&--error { color: var(--age-danger); }
	}

	&__results {
		list-style: none;
		margin: 12px 0 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__result {
		display: flex;
		align-items: center;
		gap: 12px;
		width: 100%;
		text-align: start;
		padding: 10px 12px;
		border: 1px solid transparent;
		border-radius: var(--age-radius-sm);
		background: transparent;
		cursor: pointer;

		&:hover, &:focus-visible { background: var(--age-surface-2); outline: none; }
	}

	&__avatar {
		flex: none;
		width: 34px;
		height: 34px;
		border-radius: 50%;
		display: grid;
		place-items: center;
		font-size: 13px;
		font-weight: 600;
		color: var(--age-avatar-text);
		background: var(--age-avatar-bg);
	}

	&__who { min-width: 0; display: flex; flex-direction: column; }
	&__name { font-weight: 600; font-size: 14px; color: var(--age-fg); }
	&__email {
		font-size: 12.5px; color: var(--age-fg-muted);
		overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
	}
	&__uid {
		margin-inline-start: auto;
		font-family: var(--age-mono);
		font-size: 11.5px;
		color: var(--age-fg-faint);
	}
}
</style>
