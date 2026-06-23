import {
	AsYouType,
	parsePhoneNumberFromString,
	type CountryCode,
} from 'libphonenumber-js'

export function formatPhoneAsYouType(
	input: string,
	defaultCountry?: CountryCode,
): string {
	return new AsYouType(defaultCountry).input(input)
}

export function normalisePhone(
	input: string,
	defaultCountry: CountryCode = 'KE',
): string | null {
	try {
		const parsed = parsePhoneNumberFromString(
			input,
			defaultCountry,
		)

		if (!parsed?.isValid()) {
			return null
		}

		return parsed.number // E.164
	} catch {
		return null
	}
}

export function normalisePhoneForAccount(
	input: string,
): string | null {
	const hasCountryCode = input.trim().startsWith('+')

	return normalisePhone(
		input,
		hasCountryCode ? undefined : 'KE',
	)
}
