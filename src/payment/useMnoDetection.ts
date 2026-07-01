import { reactive, computed, type Ref } from 'vue'
import type {
	MnoOption,
	ResolvedPaymentRoute,
	SelectedMno,
} from '@/payment'

export interface MnoDetectionState {
	state:
	| 'idle'
	| 'detecting'
	| 'detected' // high confidence
	| 'suggested' // ambiguous
	| 'requires-selection' // unknown
	| 'selected'
	confidence?: 'high' | 'ambiguous' | 'unknown' | null
	mno?: string | null
	country?: string | null
	reference: string | null
	options?: MnoOption[]
	selected: MnoOption | null
	showSelector: boolean      // true when user clicks "Change?" on a high-confidence result
}

/**
 * MNO detection composable.
 *
 * Drives the MNO selection UX state machine:
 *   idle → detecting → detected | suggested | requires-selection → selected
 *
 * EXTRACTED VERBATIM from PaymentStep.vue — no logic changes.
 * See https://app.plane.so/gopaperless/browse/GPL-46/
 *
 * IMPORTANT:
 * Returns the reactive `mnoDetection` object itself (not destructured)
 * so reactivity is preserved for callers in usePaymentSession that
 * read/write mnoDetection.state, .reference, etc. directly.
 */
export function useMnoDetection({
	isDaraja,
	resolution,
	fetchMobileOptions,
	isProcessing,
	canEditProvider,
}: {
	isDaraja: Ref<boolean>
	resolution: Ref<{ region?: string | null } | null>
	fetchMobileOptions: (reference: string, country: string) => Promise<{ options: MnoOption[] }>
	isProcessing: Ref<boolean>
	canEditProvider: Ref<boolean>
}) {

	const mnoDetection = reactive<MnoDetectionState>({
		state: 'idle',
		confidence: null,
		mno: null,
		country: null,
		reference: null,
		options: [],
		selected: null,
		showSelector: false,
	})

	const resolvedRoute = computed<ResolvedPaymentRoute | null>(() => {
		// Daraja has a fixed flow and doesn't require MNO detection,
		// so we can short circuit to the resolved route immediately
		if (isDaraja.value && resolution.value?.region === 'KE') {
			return {
				provider: 'M-Pesa',
				country: 'KE',
				flow: 'callback',
				source: 'daraja',
			}
		}

		// USER SELECTED
		if (mnoDetection.selected) {
			return {
				...mnoDetection.selected,
				flow: 'mobile_direct',
				source: 'dpo-selected',
			}
		}

		// AUTO DETECTED
		if (
			mnoDetection.mno &&
			mnoDetection.country
		) {
			const matchedOption = mnoDetection.options?.find(option =>
				option.provider === mnoDetection.mno &&
				option.country === mnoDetection.country
			)

			return {
				provider: matchedOption?.provider ?? mnoDetection.mno,
				country: matchedOption?.country ?? mnoDetection.country,
				logo: matchedOption?.logo,
				currency: matchedOption?.currency,
				instructions: matchedOption?.instructions,
				flow: 'mobile_direct',
				source: 'dpo-detected',
			}
		}
		return null
	})

	function isValidSelectedProvider(
		selected?: SelectedMno | null
	): boolean {
		return !!(
			selected?.mno &&
			selected?.country
		)
	}

	function formatMnoLabel(mno: string): string {
		const labels: Record<string, string> = {
			mpesa: 'M-Pesa',
			airtel: 'Airtel Money',
			mtn: 'MTN Mobile Money',
			vodacom: 'Vodacom M-Pesa',
			tigo: 'Tigo Pesa',
			halotel: 'Halotel',
			zantel: 'Zantel',
			ttcl: 'TTCL',
			ecocash: 'EcoCash',
			onemoney: 'OneWallet',
			telecash: 'Telecash',
			zamtel: 'Zamtel',
			tnm: 'TNM Mpamba',
			africell: 'Africell',
			faiba: 'Faiba',
		}
		return labels[mno.toLowerCase()] ?? mno
	}

	function selectMnoOption(option: MnoOption) {
		mnoDetection.selected = option
		mnoDetection.mno = option.provider
		mnoDetection.country = option.country
		mnoDetection.state = 'selected'
		mnoDetection.showSelector = false
	}

	async function openMnoSelector() {
		if (isDaraja.value) {
			return
		}

		if (
			isProcessing.value ||
			!canEditProvider.value
		) {
			return
		}
		mnoDetection.showSelector = false

		setTimeout(() => {
			mnoDetection.state = 'requires-selection'
			mnoDetection.showSelector = true
		}, 120)

		const options = mnoDetection.options && mnoDetection.options.length ? mnoDetection.options : []
		if (!options.length && mnoDetection.reference && mnoDetection.country) {
			try {
				const response = await fetchMobileOptions(mnoDetection.reference, mnoDetection.country)
				mnoDetection.options = response.options
			} catch {
				// options fetch failed — selector will be empty
			}
		}
	}

	function resetMnoDetectionState() {
		mnoDetection.state = 'idle'
		mnoDetection.confidence = null
		mnoDetection.mno = null
		mnoDetection.country = null
		mnoDetection.reference = null
		mnoDetection.options = []
		mnoDetection.selected = null
		mnoDetection.showSelector = false
	}

	return {
		mnoDetection,
		resolvedRoute,
		selectMnoOption,
		openMnoSelector,
		resetMnoDetectionState,
		formatMnoLabel,
		isValidSelectedProvider,
	}
}
