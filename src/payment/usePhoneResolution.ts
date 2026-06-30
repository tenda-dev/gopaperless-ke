import { ref, computed, watch, type Ref } from 'vue'
import { resolvePhone, formatAsYouType } from '@/utils/phoneResolver'

/**
 * Phone resolution composable.
 *
 * Owns phone input, format-as-you-type, resolution
 * (provider/region detection), and detected region.
 *
 * No money-moving logic, no orchestration dependency.
 * `onPhoneChanged` is injected rather than imported so this
 * composable has no direct dependency on useMnoDetection
 * composition stays unidirectional.
 *
 * EXTRACTED VERBATIM from PaymentStep.vue — no logic changes.
 * See https://app.plane.so/gopaperless/browse/GPL-49/.
 */
export function usePhoneResolution({
	isHydrating,
	providerLocked,
	hasActiveSession,
	onPhoneChanged,
}: {
	isHydrating: Ref<boolean>
	providerLocked: Ref<boolean>
	hasActiveSession: Ref<boolean>
	onPhoneChanged: () => void
}) {

	const phoneInput = ref('')
	const resolution = ref<ReturnType<typeof resolvePhone> | null>(null)
	const detectedRegion = ref<string>('KE')

	const normalisedPhone = computed(() => resolution.value?.e164 ?? '')

	watch(phoneInput, (val) => {
		if (isHydrating.value) return

		/**
		 * Prevent runtime provider drift during
		 * active orchestration session.
		 */
		if (providerLocked.value) {
			return
		}

		const formatted = formatAsYouType(val)

		if (formatted !== val) {
			phoneInput.value = formatted
			return
		}

		const res = resolvePhone(formatted)

		resolution.value = res

		if (res?.region) {
			detectedRegion.value = res.region
		}
	})

	function onPhoneInput() {
		if (hasActiveSession.value) {
			return
		}

		onPhoneChanged()
	}

	return {
		phoneInput,
		resolution,
		detectedRegion,
		normalisedPhone,
		onPhoneInput,
	}
}
