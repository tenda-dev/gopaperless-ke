import { computed, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'

/**
 * Default popular bundles shown by the slider.
 *
 * Callers can provide product-specific `snapPoints`; these values are only
 * used as a fallback when none are supplied.
 */
export const DEFAULT_SNAP_POINTS = [5, 10, 20, 50, 70, 100] as const

/**
 * Lowest value represented by the slider.
 *
 * The visible minimum (`minV`) may be higher, leaving a shaded dead zone
 * before the selectable range begins.
 */
export const DEFAULT_DOMAIN_MIN = 1

/**
 * Release distance required to snap onto a popular bundle.
 *
 * Intentionally kept small so nearby bundles (5, 10, 20, etc.) do not make
 * selecting custom quantities feel difficult.
 */
export const DEFAULT_SNAP_RADIUS_PCT = 0.015

export function clamp(n: number, lo: number, hi: number): number {
	return Math.min(hi, Math.max(lo, n))
}

export function computeMaxV(maxQuantity: number | undefined, domainMin: number): number {
	return Math.max(domainMin, Math.round(maxQuantity ?? 200))
}

export function computeMinV(
	minQuantity: number | undefined,
	domainMin: number,
	maxV: number,
): number {
	const floor = minQuantity && minQuantity > 0 ? Math.round(minQuantity) : domainMin
	return clamp(floor, domainMin, maxV)
}

export function valueToPosition(value: number, domainMin: number, maxV: number): number {
	const span = Math.max(1, maxV - domainMin)
	return (value - domainMin) / span
}

export function positionToValue(
	position: number,
	domainMin: number,
	maxV: number,
	minV: number,
): number {
	const span = Math.max(1, maxV - domainMin)
	return clamp(Math.round(domainMin + position * span), minV, maxV)
}

export function computeActiveSnaps(
	snapPoints: readonly number[],
	minV: number,
	maxV: number,
): number[] {
	return Array.from(new Set(snapPoints))
		.filter(p => Number.isFinite(p) && p >= minV && p <= maxV)
		.sort((a, b) => a - b)
}

/**
 * Returns the nearest bundle within the snap radius, or `null` to preserve
 * the user's custom quantity.
 */
export function findNearestSnap(
	position: number,
	activeSnaps: readonly number[],
	domainMin: number,
	maxV: number,
	radiusPct: number,
): number | null {
	let best = radiusPct
	let hit: number | null = null
	for (const snap of activeSnaps) {
		const distance = Math.abs(valueToPosition(snap, domainMin, maxV) - position)
		if (distance <= best) {
			best = distance
			hit = snap
		}
	}
	return hit
}

export function resolveInitialQuantity(params: {
	initialQuantity?: number
	minQuantity?: number
	maxQuantity?: number
	snapPoints: readonly number[]
	domainMin: number
}): number {
	const { initialQuantity, minQuantity, maxQuantity, snapPoints, domainMin } = params
	const maxV = computeMaxV(maxQuantity, domainMin)
	const minV = computeMinV(minQuantity, domainMin, maxV)

	if (initialQuantity != null) {
		return clamp(Math.round(initialQuantity), minV, maxV)
	}

	const firstPack = computeActiveSnaps(snapPoints, minV, maxV)[0]
	return firstPack ?? minV
}

// ── composable ───────────────────────────────────────────────────────────────

export interface UseCreditSliderOptions {
	/** Hard floor. 0 / undefined ⇒ no floor (min becomes domainMin). */
	minQuantity?: MaybeRefOrGetter<number | undefined>
	/** Sellable ceiling. Defaults to 200. */
	maxQuantity?: MaybeRefOrGetter<number | undefined>
	/** Preferred packs to snap to. Filtered/sorted/clamped internally. */
	snapPoints?: MaybeRefOrGetter<readonly number[] | undefined>
	/** Optional starting value; clamped into the valid range. */
	initialQuantity?: MaybeRefOrGetter<number | undefined>

	domainMin?: number
	snapRadiusPct?: number
}

/**
 * Encapsulates the value model and interaction behaviour for the quantity
 * slider.
 *
 * The composable is DOM-agnostic: callers provide pointer positions (0–1),
 * while it manages quantity, snapping, keyboard interaction and reactive
 * recalculation when the floor, ceiling or bundle configuration changes.
 */
export function useCreditSlider(options: UseCreditSliderOptions = {}) {
	const domainMin = options.domainMin ?? DEFAULT_DOMAIN_MIN
	const snapRadiusPct = options.snapRadiusPct ?? DEFAULT_SNAP_RADIUS_PCT

	const snapPointsInput = computed<readonly number[]>(
		() => toValue(options.snapPoints) ?? DEFAULT_SNAP_POINTS,
	)

	const maxV = computed(() => computeMaxV(toValue(options.maxQuantity), domainMin))
	const minV = computed(() => computeMinV(toValue(options.minQuantity), domainMin, maxV.value))
	const activeSnaps = computed(() => computeActiveSnaps(snapPointsInput.value, minV.value, maxV.value))

   /**
	* Indicates that the required minimum exceeds the largest quantity that can
	* be purchased. Surface this as an error instead of silently clamping.
	*/
	const hasFloorConflict = computed(() => (toValue(options.minQuantity) ?? 0) > maxV.value)

	const quantity = ref<number>(resolveInitialQuantity({
		initialQuantity: toValue(options.initialQuantity),
		minQuantity: toValue(options.minQuantity),
		maxQuantity: toValue(options.maxQuantity),
		snapPoints: snapPointsInput.value,
		domainMin,
	}))

	const dragging = ref(false)
   /**
	* Temporary thumb position while dragging.
	*
	* Represents the live pointer location before any release snapping occurs.
	*/
	const dragPosition = ref<number | null>(null)
	/**
	 * Suppress the price transition during continuous drag; re-enable for
	 * discrete commits (release-snap, keyboard, tick tap) so those still animate.
	 */
	const animatePrice = ref(true)

	const pct = (value: number) => valueToPosition(value, domainMin, maxV.value)

	const deadPct = computed(() => pct(minV.value))
	const thumbPct = computed(() => dragPosition.value ?? pct(quantity.value))
	const fillLeft = computed(() => deadPct.value * 100)
	const fillWidth = computed(() => Math.max(0, (thumbPct.value - deadPct.value) * 100))
	const isSnapped = computed(() => activeSnaps.value.includes(quantity.value))
	const ariaValueText = computed(
		() => `${quantity.value} certified signatures${isSnapped.value ? ' (popular pack)' : ''}`,
	)

	const floorPosition = (position: number) => Math.max(clamp(position, 0, 1), deadPct.value)

	function setLive(position: number) {
		dragPosition.value = position
		quantity.value = positionToValue(position, domainMin, maxV.value, minV.value)
	}

	function beginDrag(position: number) {
		dragging.value = true
		animatePrice.value = false
		setLive(floorPosition(position))
	}

	function dragTo(position: number) {
		if (!dragging.value) {
			return
		}
		setLive(floorPosition(position))
	}

	function endDrag(position: number) {
		if (!dragging.value) {
			return
		}
		dragging.value = false

		const fp = floorPosition(position)
		const snapped = findNearestSnap(fp, activeSnaps.value, domainMin, maxV.value, snapRadiusPct)

		animatePrice.value = true
		// Snap only if released within radius of a pack; otherwise keep the
		// exact custom value the user landed on.
		quantity.value = clamp(
			Math.round(snapped ?? positionToValue(fp, domainMin, maxV.value, minV.value)),
			minV.value,
			maxV.value,
		)
		dragPosition.value = null
	}

	function cancelDrag() {
		if (!dragging.value) {
			return
		}
		dragging.value = false
		dragPosition.value = null
	}

   /**
	* Commits a discrete quantity selection.
	*
	* Used by keyboard navigation, bundle selection and other non-drag
	* interactions. Unlike live dragging, committed changes animate the price.
	*/
	function commit(value: number) {
		animatePrice.value = true
		quantity.value = clamp(Math.round(value), minV.value, maxV.value)
	}

	const stepBy = (delta: number) => commit(quantity.value + delta)
	const toMin = () => commit(minV.value)
	const toMax = () => commit(maxV.value)
	const selectSnap = (value: number) => commit(value)

	function jumpToNextSnap() {
		const next = activeSnaps.value.find(s => s > quantity.value)
		commit(next ?? maxV.value)
	}

	function jumpToPrevSnap() {
		let prev: number | null = null
		for (const s of activeSnaps.value) {
			if (s < quantity.value) {
				prev = s
			}
		}
		commit(prev ?? minV.value)
	}

	// Keep the selected quantity valid if the floor or ceiling changes.
	// Update directly (rather than via `commit`) so external configuration
	// changes don't trigger the price animation.
	watch(minV, (m) => {
		if (quantity.value < m) {
			quantity.value = m
		}
	})
	watch(maxV, (m) => {
		if (quantity.value > m) {
			quantity.value = m
		}
	})

	return {
		// state
		quantity,
		dragging,
		animatePrice,
		// derived
		minV,
		maxV,
		activeSnaps,
		hasFloorConflict,
		deadPct,
		thumbPct,
		fillLeft,
		fillWidth,
		isSnapped,
		ariaValueText,
		// geometry
		pct,
		// interaction (positions in [0, 1])
		beginDrag,
		dragTo,
		endDrag,
		cancelDrag,
		// discrete
		commit,
		stepBy,
		toMin,
		toMax,
		selectSnap,
		jumpToNextSnap,
		jumpToPrevSnap,
	}
}

export type UseCreditSliderReturn = ReturnType<typeof useCreditSlider>
