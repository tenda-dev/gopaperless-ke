<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\MnoSuggestionValidationResultDTO;
use Psr\Log\LoggerInterface;

final class MnoSuggestionValidatorService {
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
			'Airtel' => [
				'airtelgh',
			],
		],

		'CI' => [
			'MTN' => [
				'mtnci',
			],
			'Orange' => [
				'orangeci',
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
		private LoggerInterface $logger,
	) {
	}

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
			$suggestedMno === null
			|| $region === null
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
		 * Build a set of aliases for the suggested MNO.
		 *
		 * The DPO mobile option names returned by GetMobilePaymentOptions
		 * can be either the user-facing name ("airtel", "mpesa") or a
		 * terminal-specific identifier ("airtelke", "safaricomstkv2").
		 * We therefore match by exact alias, substring, and the configured
		 * mapping so the charge step sends the exact string DPO returned.
		 */
		$baseAlias = strtolower($suggestedMno);
		$aliases = array_unique(
			array_merge(
				[$baseAlias],
				array_map(
					'strtolower',
					self::DPO_MNO_PROVIDER_MAPPING[$region][$suggestedMno] ?? []
				)
			)
		);

		$aliasSet = array_flip($aliases);

		foreach ($options as $option) {
			$optionProvider = strtolower((string)($option['provider'] ?? ''));

			if ($optionProvider === '') {
				continue;
			}

			$exactMatch = isset($aliasSet[$optionProvider]);
			$fuzzyMatch = str_contains($optionProvider, $baseAlias)
				|| str_contains($baseAlias, $optionProvider);

			if ($exactMatch || $fuzzyMatch) {
				/**
				 * Suggested MNO is supported by DPO for this region.
				 * Return the provider string exactly as DPO returned it
				 * so the charge step uses the terminal-native identifier.
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
		$this->logger->warning(
			'[MnoSuggestionValidator] Suggested MNO not found in DPO options',
			[
				'region' => $region,
				'mno' => $suggestedMno,
				'aliases' => $aliases,
				'optionProviders' => array_map(
					static fn (array $o): string => strtolower((string)($o['provider'] ?? '')),
					$options
				),
			]
		);

		return new MnoSuggestionValidationResultDTO(
			ResolutionConfidence::UNKNOWN,
			null,
		);
	}

	/**
	 * Reverse of validate(): map a DPO provider/execution key back to the
	 * canonical MNO identity for a region.
	 *
	 * EXACT alias match only (base alias + configured DPO aliases) — never
	 * fuzzy — so provider-native keys like "selcom_webpay_airtel" do not
	 * accidentally collapse into a different MNO ("selcom_webpay" = Vodacom).
	 * Returns the lowercased canonical MNO (e.g. "airtelke" -> "airtel",
	 * "safaricomstkv2" -> "mpesa"), or null when the key is not recognised.
	 */
	public function canonicalMnoForOption(?string $providerKey, ?string $region): ?string {
		if ($providerKey === null || $region === null) {
			return null;
		}

		$providerKey = strtolower(trim($providerKey));
		$region = strtoupper(trim($region));

		if ($providerKey === '' || !isset(self::DPO_MNO_PROVIDER_MAPPING[$region])) {
			return null;
		}

		foreach (self::DPO_MNO_PROVIDER_MAPPING[$region] as $canonicalMno => $dpoAliases) {
			$aliasSet = array_merge(
				[strtolower($canonicalMno)],
				array_map('strtolower', $dpoAliases),
			);

			if (in_array($providerKey, $aliasSet, true)) {
				return strtolower($canonicalMno);
			}
		}

		return null;
	}
}
