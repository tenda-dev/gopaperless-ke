<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\MnoSuggestionValidationResultDTO;
use Psr\Log\LoggerInterface;

final class MnoSuggestionValidatorService
{
	private const DPO_MNO_PROVIDER_MAPPING = [

		'KE' => [
			'Mpesa' => [
				'safaricomstkv2',
			],
			'Airtel' => [
				'airtelke',
			],
		],

		'TZ' => [
			'Vodacom' => [
				'selcom_webpay',
			],
			'Airtel' => [
				'selcom_webpay_airtel',
			],
			'Tigo' => [
				'tigodebitmandate',
			],
			'Halotel' => [
				'selcom_webpay_halotel',
			],
			'TTCL' => [
				'selcom_webpay_ttcl',
			],
			'Zantel' => [
				'selcom_webpay_zantel',
			],
		],

		'UG' => [
			'MTN' => [
				'mtnmobilemoney',
			],
			'Airtel' => [
				'mobile_airtel_ug',
			],
		],

		'RW' => [
			'MTN' => [
				'mtn',
			],
			'Airtel' => [
				'airtelrw',
			],
		],

		'GH' => [
			'MTN' => [
				'mtn',
			],
			'Vodacom' => [
				'vodagh',
			],
		],

		'MW' => [
			'Airtel' => [
				'airtelmw',
			],
		],

		'ZM' => [
			'MTN' => [
				'mtnzm',
			],
			'Airtel' => [
				'airtelzm',
			],
		],

		'ZW' => [
			'EcoCash' => [
				'ecocash',
			],
		],
	];

	public function __construct(
		private LoggerInterface $logger
	) {}

	/**
	 * Validates whether the suggested MNO from routing
	 * exists in the DPO mobile options snapshot.
	 *
	 * Returns HIGH confidence when the suggestion can be
	 * mapped to a provider present in the DPO response.
	 *
	 * Otherwise returns UNKNOWN forcing FE provider
	 * selection.
	 *
	 */
	public function validate(
		?string $suggestedMno,
		?string $region,
		array $options,
	): MnoSuggestionValidationResultDTO {

		if (
			$suggestedMno === null ||
			$region === null
		) {
			return new MnoSuggestionValidationResultDTO(
				ResolutionConfidence::UNKNOWN,
				null,
			);
		}

		/**
		 * Normalise routing values before lookup.
		 */
		$region = strtoupper(trim($region));
		$suggestedMno = trim($suggestedMno);

		/**
		 * Resolve expected DPO provider identifiers for the
		 * suggested MNO within the detected region.
		 */
		$expectedProviders = self::DPO_MNO_PROVIDER_MAPPING[$region][$suggestedMno]
			?? [];

		/**
		 * Unknown mappings should not block payment flow.
		 * Downgrade confidence and require FE selection.
		 */
		if ($expectedProviders === []) {
			$this->logger->warning(
				'[MnoSuggestionValidator] Missing DPO mapping',
				[
					'region' => $region,
					'mno' => $suggestedMno,
				]
			);

			return new MnoSuggestionValidationResultDTO(
				ResolutionConfidence::UNKNOWN,
				null,
			);
		}

		/**
		 * Convert expected providers into a lookup set for
		 * constant-time case-insensitive membership checks.
		 */
		$expectedProviders = array_flip(
			array_map(
				'strtolower',
				$expectedProviders,
			)
		);

		foreach ($options as $option) {

			if (isset(
					$expectedProviders[
						strtolower($option['provider'])
					]
			)) {
				/**
				 * Suggested MNO is supported by DPO for this region.
				 */
				return new MnoSuggestionValidationResultDTO(
					ResolutionConfidence::HIGH,
					$option['provider'],
				);
			}
		}

		/**
		 * Suggested MNO could not be validated against DPO options.
		 * Require explicit user selection.
		 */
		return new MnoSuggestionValidationResultDTO(
			ResolutionConfidence::UNKNOWN,
			null,
		);
	}
}
