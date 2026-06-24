<!-- UserAvatar.vue -->
<template>
  <div class="user-avatar" aria-hidden="true">
    {{ initial }}
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ email: string }>()

const initial = computed(() =>
  props.email.charAt(0).toUpperCase()
)
</script>

<style scoped lang="scss">
.user-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #059669;
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  user-select: none;
}
</style>
