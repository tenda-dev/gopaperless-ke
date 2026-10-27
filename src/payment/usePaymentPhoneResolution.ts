import type { Ref } from 'vue'
import {
	formatAsYouType,
	resolvePhone,
} from '@/utils/phoneResolver'
import type { SupportedRegion } from '@/utils/phoneResolver'

export type PhoneResolution = ReturnType<typeof resolvePhone>

interface UsePhoneResolutionOptions {
	phoneInput: Ref<string>
	defaultPhoneRegion: SupportedRegion
	resolution: Ref<PhoneResolution | null>
	detectedRegion: Ref<string>
	isHydrating: Ref<boolean>
	providerLocked: Ref<boolean>
}

export function usePaymentPhoneResolution(options: UsePhoneResolutionOptions) {
	function resolveInput(value: string): PhoneResolution {
		return resolvePhone(value, options.defaultPhoneRegion)
	}

	function formatInput(value: string): string {
		return formatAsYouType(value)
	}

	function applyPhoneInput(value: string): void {
		if (options.isHydrating.value || options.providerLocked.value) {
			return
		}

		const formatted = formatInput(value)

		if (formatted !== value) {
			options.phoneInput.value = formatted
			return
		}

		const result = resolveInput(formatted)
		options.resolution.value = result

		if (result?.region) {
			options.detectedRegion.value = result.region
		}
	}


	return {
		resolveInput,
		formatInput,
		applyPhoneInput,
	}
}
