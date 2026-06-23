<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\PhoneNumber\DTO;

final readonly class PhoneNumberDTO
{
	public function __construct(
		/**
		 * Whether the supplied phone number was successfully
		 * parsed and validated.
		 */
		public bool $valid,

		/**
		 * Phone number formatted according to E.164.
		 *
		 * Example:
		 * +254712345678
		 */
		public ?string $e164,

		/**
		 * E.164 phone number without the leading '+'.
		 *
		 * Example:
		 * 254712345678
		 *
		 * Useful for integrations that do not accept '+'.
		 */
		public ?string $e164Digits,

		/**
		 * National significant number.
		 *
		 * Example:
		 * 712345678
		 */
		public ?string $national,

		/**
		 * Masked representation suitable for logs and audit trails.
		 *
		 * Examples:
		 * - ********5678
		 * - null when the phone number is invalid or unavailable.
		 */
		public ?string $masked,

		/**
		 * ISO 3166-1 alpha-2 region code.
		 *
		 * Examples:
		 * KE, TZ, UG
		 */
		public ?string $region,

		/**
		 * International country calling code.
		 *
		 * Examples:
		 * 254, 255, 256
		 */
		public ?string $countryCallingCode,
	) {}
}
