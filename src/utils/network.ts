import { ref } from 'vue'

export const isOffline = ref(!navigator.onLine)

export function markOffline() {
  isOffline.value = true
}

export function markOnline() {
  isOffline.value = false
}

// A request timeout (ECONNABORTED) is deliberately NOT treated as a network
// error: it produced false positives. A slow request that timed out is not
// the same as a lost connection.
export function isNetworkError(error: any): boolean {
  return !error.response && !!error.request
}
