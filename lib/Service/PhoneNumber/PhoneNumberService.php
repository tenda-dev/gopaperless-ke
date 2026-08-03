<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\PhoneNumber;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use OCA\Libresign\Service\PhoneNumber\DTO\PhoneNumberDTO;
use Psr\Log\LoggerInterface;

final class PhoneNumberService {

	private PhoneNumberUtil $phoneUtil;

	public function __construct(
		private LoggerInterface $logger,
	) {
		$this->phoneUtil = PhoneNumberUtil::getInstance();
	}


	/**
	 * Resolve a phone number into a validated, normalised representation.
	 *
	 * Responsibilities:
	 * - Validate the supplied phone number using libphonenumber
	 * - Normalise valid numbers to E.164 format
	 * - Extract region information
	 * - Extract national number and country calling code
	 *
	 * Official contract:
	 * - Clients should submit phone numbers in E.164 format.
	 *
	 * Compatibility:
	 * - Kenyan local numbers starting with 07 or 01 are assumed
	 *   to belong to the KE region to support legacy inputs.
	 *
	 * This method never throws. Invalid or unparsable input
	 * results in an invalid PhoneNumberDTO.
	 */
	public function resolve(string $rawPhone): PhoneNumberDTO {
		try {
			$rawPhone = trim($rawPhone);

			if ($rawPhone === '') {
				return $this->invalid();
			}

			/*
			 * Official contract:
			 * FE sends E.164.
			 *
			 * Compatibility layer:
			 * Assume Kenyan local numbers.
			 */
			$defaultRegion = null;

			if (
				str_starts_with($rawPhone, '07')
				|| str_starts_with($rawPhone, '01')
			) {
				$defaultRegion = 'KE';
			}

			$parsed = $this->phoneUtil->parse(
				$rawPhone,
				$defaultRegion
			);

			if (!$this->phoneUtil->isValidNumber($parsed)) {

				$this->logger->warning(
					'[PhoneNumber] Invalid phone number',
					[
						'input' => $this->mask($rawPhone),
						'inputLength' => strlen($rawPhone),
					]
				);

				return $this->invalid();
			}

			$e164 = $this->phoneUtil->format(
				$parsed,
				PhoneNumberFormat::E164
			);

			$nationalNumber = (string)$parsed->getNationalNumber();

			$countryCallingCode = (string)$parsed->getCountryCode();

			return new PhoneNumberDTO(
				valid: true,
				e164: $e164,
				e164Digits: ltrim($e164, '+'),
				national: $nationalNumber,
				masked: $this->mask($e164),
				region: $this->phoneUtil
					->getRegionCodeForNumber($parsed),
				countryCallingCode: $countryCallingCode,
			);
		} catch (NumberParseException $e) {

			$this->logger->warning(
				'[PhoneNumber] Parse failed',
				[
					'input' => $this->mask($rawPhone),
					'inputLength' => strlen($rawPhone),
					'error' => $e->getMessage(),
				]
			);

			return $this->invalid();
		}
	}

	/**
	 * Create an invalid phone number resolution result.
	 *
	 * Used whenever a phone number cannot be parsed or fails
	 * validation checks.
	 */
	private function invalid(): PhoneNumberDTO {
		return new PhoneNumberDTO(
			valid: false,
			e164: null,
			e164Digits: null,
			national: null,
			masked: null,
			region: null,
			countryCallingCode: null,
		);
	}


	/**
	 * Generate a masked representation suitable for logs.
	 *
	 * Examples:
	 * +254712345678 -> ****5678
	 * 254712345678  -> ****5678
	 *
	 * Returns null when no number is available.
	 */
	private function mask(?string $e164): ?string {
		if ($e164 === null) {
			return null;
		}

		$digits = preg_replace('/\D/', '', $e164);

		if ($digits === null || strlen($digits) < 4) {
			return '****';
		}

		return sprintf(
			'****%s',
			substr($digits, -4),
		);
	}
}
