/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted } from 'vue'
import type { ComputedRef } from 'vue'

import { useDialogCoordinatorStore } from '@/store/dialogCoordinator'
import type { ManagedDialogOptions } from '@/store/dialogCoordinator'

/**
 * Registers an automatic dialog with the coordinator for the lifetime of its
 * component. Presentation permission is exposed to the participant, which
 * retains ownership of its eligibility, visibility, and business logic.
 */
export function useManagedDialog(options: ManagedDialogOptions): { canPresent: ComputedRef<boolean> } {
	const store = useDialogCoordinatorStore()

	onMounted(() => store.register(options))
	onUnmounted(() => store.unregister(options.id))

	const canPresent = computed(() => store.canPresent(options.id))
	return { canPresent }
}
