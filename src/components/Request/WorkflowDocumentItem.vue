<template>
  <div
    class="document-item"
    :class="{ active: isActive, flash: isFlashing }"
    @click="handleSelect"
  >
    <!-- THUMBNAIL -->
    <div class="item-thumb">
      <img
        v-if="previewUrl"
        class="item-thumb-img"
        :class="{ loaded: previewLoaded }"
        :src="previewUrl"
        alt=""
        @load="previewLoaded = true; previewFailed = false"
        @error="previewFailed = true; previewLoaded = false"
      />

      <div class="item-thumb-fallback" :class="{ hidden: previewLoaded && !previewFailed }">
        <div class="item-pdf-block" :style="{ background: fileColor }">
          PDF
        </div>
      </div>

      <div class="item-type-badge">
        <NcIconSvgWrapper :path="icon" :size="12" />
      </div>
    </div>

    <!-- BODY -->
    <div class="item-body">

      <!-- NAME + STATUS -->
      <div class="item-top-row">
        <span class="item-name">{{ displayName }}</span>

        <span class="item-pill" :class="[pillVariant, { 'pill-animate': isPillAnimating }]">
          <span class="item-pill-dot" />
          {{ statusLabel }}
        </span>
      </div>

      <!-- META -->
      <div class="item-meta-strip">
        <div class="item-meta">
          <NcIconSvgWrapper :path="mdiFileMultipleOutline" :size="12" />
          <span><b>{{ pages }}</b> {{ pages === 1 ? 'page' : 'pages' }}</span>
        </div>

        <div class="item-meta">
          <NcIconSvgWrapper :path="mdiCalendarOutline" :size="12" />
          <span><b>{{ formattedDate }}</b></span>
        </div>

        <div class="item-meta">
          <NcIconSvgWrapper :path="mdiAccountMultipleOutline" :size="12" />
          <span><b>{{ signersCount }}</b> {{ signersCount === 1 ? 'signer' : 'signers' }}</span>
        </div>
      </div>

      <!-- ENVELOPE SUB-FILES -->
      <div v-if="isMulti && filePreview" class="item-subfiles">
        <NcIconSvgWrapper :path="mdiPaperclip" :size="11" />
        {{ filePreview }}
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { generateOcsUrl } from '@nextcloud/router'

import {
  mdiFileDocumentOutline,
  mdiFileMultipleOutline,
  mdiFolder,
  mdiCalendarOutline,
  mdiAccountMultipleOutline,
  mdiPaperclip,
} from '@mdi/js'

const props = defineProps<{
  file: any
  isActive?: boolean
}>()

const emit = defineEmits<{
  (e: 'select', id: number): void
}>()

/* ── PREVIEW ─────────────────────────── */
const previewLoaded = ref(false)
const previewFailed = ref(false)

const previewUrl = computed(() => {
  let url = ''
  if (props.file.nodeId) {
    url = generateOcsUrl('/apps/libresign/api/v1/file/thumbnail/{nodeId}', {
      nodeId: props.file.nodeId,
    })
  } else if (props.file.id) {
    url = generateOcsUrl('/apps/libresign/api/v1/file/thumbnail/file_id/{fileId}', {
      fileId: props.file.id,
    })
  } else {
    return null
  }
  const u = new URL(url)
  u.searchParams.set('x', '192')
  u.searchParams.set('y', '240')
  u.searchParams.set('mimeFallback', 'true')
  u.searchParams.set('a', '0')
  return u.toString()
})

// Reset on file change
watch(() => props.file.id, () => {
  previewLoaded.value = false
  previewFailed.value = false
})

/* ── TYPE ────────────────────────────── */
const isMulti = computed(() => {
  const count = props.file.filesCount || props.file.files?.length || 1
  return count > 1
})

const icon = computed(() => isMulti.value ? mdiFolder : mdiFileDocumentOutline)

// Deterministic color from file id
const FILE_COLORS = ['#e8453c', '#6366f1', '#059669', '#d97706', '#0284c7', '#9333ea']
const fileColor = computed(() => {
  const id = typeof props.file.id === 'number' ? props.file.id : 0
  return FILE_COLORS[id % FILE_COLORS.length]
})

/* ── DISPLAY ─────────────────────────── */
const displayName = computed(() => {
  if (!isMulti.value && props.file.files?.[0]) return props.file.files[0].name
  return props.file.name
})

const pages = computed(() => {
  if (isMulti.value) {
    return props.file.files?.reduce((t: number, f: any) =>
      t + (f.metadata?.p ?? f.totalPages ?? 0), 0) ?? 0
  }
  return props.file.metadata?.p ?? props.file.files?.[0]?.metadata?.p ?? 0
})

const signersCount = computed(() => props.file.signersCount ?? 0)

const formattedDate = computed(() => {
  const f = props.file
  if (!('created_at' in f) || !f.created_at) return '—'
  return new Date(f.created_at).toLocaleDateString(undefined, {
    day: '2-digit', month: '2-digit', year: 'numeric',
  })
})

const filePreview = computed(() => {
  if (!isMulti.value) return ''
  return props.file.files?.slice(0, 2).map((f: any) => f.name).join(', ') ?? ''
})

/* ── STATUS ──────────────────────────── */
const statusLabel = computed(() => {
  if (!props.file.signersCount) return 'Add signer'
  if (!props.file.visibleElements?.length) return 'Set positions'
  return 'Ready'
})

const pillVariant = computed(() => {
  if (!props.file.signersCount) return 'pill-warn'
  if (!props.file.visibleElements?.length) return 'pill-pos'
  return 'pill-ok'
})

/* ── INTERACTIONS ────────────────────── */
const isFlashing = ref(false)
const isPillAnimating = ref(false)

function handleSelect() {
  if (typeof props.file.id === 'number') {
    emit('select', props.file.id)
  }
}

watch(
  () => [props.file.signersCount, props.file.visibleElements?.length],
  () => {
    isFlashing.value = false
    isPillAnimating.value = false
    requestAnimationFrame(() => {
      isFlashing.value = true
      isPillAnimating.value = true
      setTimeout(() => {
        isFlashing.value = false
        isPillAnimating.value = false
      }, 400)
    })
  },
)
</script>

<style scoped>
/* ── CARD ─────────────────────────────── */
.document-item {
  display: flex;
  align-items: stretch;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  cursor: pointer;
  position: relative;
  transition:
    transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
    border-color 160ms ease,
    box-shadow 200ms cubic-bezier(0.22, 1, 0.36, 1);
  will-change: transform;
}

.document-item:hover {
  transform: translateY(-2px);
  border-color: rgba(4, 213, 109, 0.35);
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.07);
}

.document-item:active {
  transform: scale(0.99);
  box-shadow: none;
  transition-duration: 80ms;
}

.document-item.active {
  border-color: #04d56d;
  background: rgba(4, 213, 109, 0.05);
  box-shadow: 0 0 0 2px rgba(4, 213, 109, 0.1);
}

.document-item.active::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 3px;
  background: #04d56d;
  z-index: 2;
}

@keyframes cardFlash {
  0%   { background: rgba(4, 213, 109, 0.12); }
  100% { background: var(--color-main-background); }
}
.document-item.flash {
  animation: cardFlash 0.4s ease forwards;
}

/* ── THUMBNAIL ────────────────────────── */
.item-thumb {
  width: 80px;
  flex-shrink: 0;
  background: var(--color-background-dark);
  border-right: 1px solid var(--color-border);
  position: relative;
  overflow: hidden;
}

.item-thumb::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.07), transparent 50%);
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.2s ease;
}
.document-item:hover .item-thumb::after { opacity: 1; }

.item-thumb-img {
  width: 100%; height: 100%;
  object-fit: cover;
  display: block;
  opacity: 0;
  transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.25s ease;
}
.item-thumb-img.loaded { opacity: 1; }
.document-item:hover .item-thumb-img { transform: scale(1.04); }

.item-thumb-fallback {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  transition: opacity 0.2s ease;
}
.item-thumb-fallback.hidden { opacity: 0; pointer-events: none; }

.item-pdf-block {
  width: 48px; height: 56px;
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; letter-spacing: 0.6px;
  color: white;
  position: relative;
  transition: transform 0.25s ease;
}
.item-pdf-block::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 12px; height: 12px;
  background: rgba(255,255,255,0.22);
  clip-path: polygon(100% 0, 0 0, 100% 100%);
  border-radius: 0 7px 0 0;
}
.document-item:hover .item-pdf-block { transform: translateY(-2px) scale(1.03); }

.item-type-badge {
  position: absolute;
  bottom: 7px; right: 7px;
  width: 22px; height: 22px;
  border-radius: 50%;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
  transition: transform 0.2s ease;
  z-index: 1;
  color: var(--color-text-maxcontrast);
}
.document-item:hover .item-type-badge { transform: scale(1.1); }

/* ── BODY ─────────────────────────────── */
.item-body {
  flex: 1;
  padding: 11px 14px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
}

.item-top-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.item-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-main-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
  line-height: 1.4;
}

/* ── STATUS PILL ──────────────────────── */
.item-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
  flex-shrink: 0;
  white-space: nowrap;
}

.item-pill-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

.pill-warn { background: #fff8e6; color: #9a5500; }
.pill-ok   { background: #e6faf1; color: #166534; }
.pill-pos  { background: #eef2ff; color: #3730a3; }

@keyframes dotPulse {
  0%   { transform: scale(1); }
  50%  { transform: scale(1.8); opacity: 0.5; }
  100% { transform: scale(1); }
}
.item-pill.pill-animate .item-pill-dot {
  animation: dotPulse 0.4s ease;
}

/* ── META STRIP ───────────────────────── */
.item-meta-strip {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
}

.item-meta {
  display: flex;
  align-items: center;
  gap: 4px;
  padding-right: 10px;
  margin-right: 10px;
  /* border-right: 1px solid var(--color-border); */
  font-size: 11px;
  color: var(--color-text-maxcontrast);
}
.item-meta:last-child {
  border-right: none;
  padding-right: 0;
  margin-right: 0;
}
.item-meta b {
  font-weight: 600;
  color: var(--color-main-text);
}

/* ── SUBFILES ─────────────────────────── */
.item-subfiles {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: -4px;
}

/* ── MOBILE ───────────────────────────── */
@media (max-width: 540px) {
  .item-thumb { width: 72px; }
  .item-pdf-block { width: 38px; height: 46px; font-size: 10px; }
  .item-body { padding: 10px 12px; gap: 6px; }
  .item-name { font-size: 12px; }
  .item-pill { font-size: 10px; padding: 2px 7px; }
  .item-meta { font-size: 10px; padding-right: 8px; margin-right: 8px; }
}

@media (max-width: 400px) {
  .item-thumb { width: 58px; }
  .item-pdf-block { width: 32px; height: 38px; font-size: 9px; }
  .item-top-row { flex-direction: column; gap: 5px; }
  .item-pill { align-self: flex-start; }
  /* drop signers on very small screens — least critical */
  .item-meta:last-child { display: none; }
}

@media (max-width: 768px) {
  .item-meta-strip {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
  }

  .item-meta {
    border-right: none;
    margin-right: 0;
    padding-right: 0;
  }
}
</style>
