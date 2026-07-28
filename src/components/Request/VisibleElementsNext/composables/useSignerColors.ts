/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Stable, distinct colour assignment per signer (for any number of signers).
 *
 * The original editor coloured boxes with `usernameToColor(seed)`, whose hash
 * collapses similar seeds into near-identical faint tints.
 * Here the first three signers are pinned to the palette
 * (purple / teal / amber) and everyone after that gets an evenly-spaced hue via
 * the golden-angle rotation, so N signers always yield N visually-separated
 * colours. Assignment is by first-seen order and cached, so a signer keeps the
 * same colour across the sidebar, the canvas and the page rail.
 */

import { computed, ref, type Ref } from 'vue'

export type Palette = {
	/** Solid colour for borders, avatars and dots. */
	base: string
	/** Faint fill used behind a placed box. */
	tint: string
	/** Readable text colour on top of the solid `base`. */
	contrast: string
}

type SignerColorKey = string | number

/** First three match the approved mockups; the rest are generated. */
const PINNED_HUES: ReadonlyArray<string> = Object.freeze([
	'#534AB7', // purple
	'#1D9E75', // teal
	'#BA7517', // amber
])

const GOLDEN_ANGLE = 137.508
const GENERATED_SATURATION = 62
const GENERATED_LIGHTNESS = 44
const TINT_ALPHA = 0.14

function hslToRgb(h: number, s: number, l: number): { r: number, g: number, b: number } {
	const sat = s / 100
	const lig = l / 100
	const c = (1 - Math.abs(2 * lig - 1)) * sat
	const x = c * (1 - Math.abs(((h / 60) % 2) - 1))
	const m = lig - c / 2
	let r = 0
	let g = 0
	let b = 0
	if (h < 60) { r = c; g = x } else if (h < 120) { r = x; g = c } else if (h < 180) { g = c; b = x } else if (h < 240) { g = x; b = c } else if (h < 300) { r = x; b = c } else { r = c; b = x }
	return {
		r: Math.round((r + m) * 255),
		g: Math.round((g + m) * 255),
		b: Math.round((b + m) * 255),
	}
}

function hexToRgb(hex: string): { r: number, g: number, b: number } {
	const value = hex.replace('#', '')
	return {
		r: parseInt(value.slice(0, 2), 16),
		g: parseInt(value.slice(2, 4), 16),
		b: parseInt(value.slice(4, 6), 16),
	}
}

function rgbToHex(r: number, g: number, b: number): string {
	const toHex = (n: number) => n.toString(16).padStart(2, '0')
	return `#${toHex(r)}${toHex(g)}${toHex(b)}`
}

/** WCAG relative luminance → pick black or white text for contrast. */
function contrastColor(r: number, g: number, b: number): string {
	const channel = (c: number) => {
		const v = c / 255
		return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
	}
	const luminance = 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
	return luminance > 0.5 ? '#1a1a1a' : '#ffffff'
}

function buildPalette(index: number): Palette {
	let rgb: { r: number, g: number, b: number }
	if (index < PINNED_HUES.length) {
		rgb = hexToRgb(PINNED_HUES[index])
	} else {
		// Offset the generated sequence so it doesn't land back on the pinned hues.
		const hue = (index * GOLDEN_ANGLE + 15) % 360
		rgb = hslToRgb(hue, GENERATED_SATURATION, GENERATED_LIGHTNESS)
	}
	const base = rgbToHex(rgb.r, rgb.g, rgb.b)
	return {
		base,
		tint: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${TINT_ALPHA})`,
		contrast: contrastColor(rgb.r, rgb.g, rgb.b),
	}
}

const FALLBACK_PALETTE: Palette = buildPalette(0)

export type UseSignerColors = {
	/** Reactive `{ [signRequestId]: Palette }` for binding into PdfEditor. */
	colorMap: Ref<Record<SignerColorKey, Palette>>
	/** Ensure a key has a colour and return it (assigns next hue if new). */
	colorFor: (key: SignerColorKey | null | undefined) => Palette
	/** Seed colours for a batch of keys, preserving order. */
	registerKeys: (keys: Array<SignerColorKey | null | undefined>) => void
	/** Clear all assignments (call when the modal closes / doc changes). */
	reset: () => void
}

export function useSignerColors(): UseSignerColors {
	const assignments = ref<Record<SignerColorKey, Palette>>({})
	let nextIndex = 0

	function normaliseKey(key: SignerColorKey | null | undefined): SignerColorKey | null {
		if (key === null || key === undefined || key === '') {
			return null
		}
		return typeof key === 'number' ? key : String(key)
	}

	function colorFor(key: SignerColorKey | null | undefined): Palette {
		const normalised = normaliseKey(key)
		if (normalised === null) {
			return FALLBACK_PALETTE
		}
		const existing = assignments.value[normalised]
		if (existing) {
			return existing
		}
		const palette = buildPalette(nextIndex++)
		assignments.value = { ...assignments.value, [normalised]: palette }
		return palette
	}

	function registerKeys(keys: Array<SignerColorKey | null | undefined>): void {
		keys.forEach((key) => {
			colorFor(key)
		})
	}

	function reset(): void {
		assignments.value = {}
		nextIndex = 0
	}

	const colorMap = computed(() => assignments.value)

	return {
		colorMap: colorMap as Ref<Record<SignerColorKey, Palette>>,
		colorFor,
		registerKeys,
		reset,
	}
}
