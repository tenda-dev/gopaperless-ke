<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\Dispatchers\VerificationDispatcherFactory;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyChargeDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Deferred mobile charge step for DPO mobile_direct flow.
 *
 * startPayment() only reserves the transaction; this service triggers
 * the actual provider charge and persists instructions for the UI.
 */
class MobileMoneyChargeService {
	public function __construct(
		private PaymentRepository $paymentRepository,
		private MobileMoneyService $mobileMoneyService,
		private PaymentResponseFactory $responseFactory,
		private AmountResolver $amountResolver,
		private PaymentDateTimeHelper $dateTimeHelper,
		private LoggerInterface $logger,
		private VerificationDispatcherFactory $verificationDispatcher,
		private PhoneMnoResolver $phoneMnoResolver,
		private MnoSuggestionValidatorService $mnoSuggestionValidatorService,
	) {
	}

	public function chargeMobile(
		string $reference,
		string $phone,
		?string $inputMno = null,
		?string $inputCountry = null,
	): ExistingPaymentResultDTO {
		$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new RuntimeException('chargeMobile only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $this->responseFactory->buildExistingPaymentResponse($payment);
		}

		$paymentPhone = $payment->getPhoneE164Digits();

		if ($paymentPhone === null || $paymentPhone === '') {
			throw new RuntimeException('Payment phone number is missing');
		}

		if ($phone !== $paymentPhone) {
			throw new RuntimeException('Phone number does not match payment');
		}

		$phone = $paymentPhone;

		$meta = $payment->getProviderMetadataObject();

		if ($meta->alreadyCharged) {
			return $this->responseFactory->buildExistingPaymentResponse($payment);
		}

		if (
			$payment->getDisplayAmount() === null
			|| $payment->getDisplayCurrency() === null
		) {
			throw new RuntimeException('Missing display pricing information');
		}

		$displayAmountMinor = $payment->getDisplayAmount();
		$displayCurrency = $payment->getDisplayCurrency();

		$suggested = $meta->suggested;
		$selection = $meta->selection;

		$options = $selection->options;
		$hasOptions = !empty($options);
		$requiresSelection = $selection->required;

		$providerMnoKey = null;
		$country = null;

		if ($inputMno && $inputCountry) {
			$providerMnoKey = strtolower($inputMno);
			$country = strtolower($inputCountry);
		} else {
			$providerMnoKey = strtolower($suggested->mno ?? '');
			$country = strtolower($suggested->country ?? '');
		}

		if ($hasOptions) {
			$this->validateOptionsSelection($options, $providerMnoKey, $country);
		} elseif (!$providerMnoKey || !$country) {
			throw new RuntimeException('Missing MNO selection');
		}

		$selected = new SelectedMnoDTO($providerMnoKey, $country);

		$amount = $this->amountResolver->toMajorUnits(
			$displayAmountMinor,
			$displayCurrency
		);

		$result = $this->mobileMoneyService->charge(
			new MobileMoneyChargeDTO(
				providerReference: $reference,
				phone: $phone,
				providerMnoKey: $providerMnoKey,
				country: $country,
				amount: $amount,
				currency: $displayCurrency,
			)
		);

		$providerPayload = $this->getProviderPayload($meta)
			->withCharge(
				$result->meta['providerPayload']['charge'] ?? []
			);

		$chargeSent = $result->isExecuting() || $result->isReconciling() || $result->isSuccess();

		$meta = $meta->with(
			updatedAt: $this->dateTimeHelper->nowImmutable(),
			providerPayload: $providerPayload,
			alreadyCharged: $meta->alreadyCharged || $chargeSent,
			selected: $selected,
			instructions: $result->meta['instructions'] ?? 'Check your phone and approve Phone STK Push'
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentRepository->update($payment);

		// Memoize the user-confirmed MNO for this phone once the DPO charge has
		// been dispatched and payment metadata persisted. The cache stores the
		// canonical MNO identity separately from the provider-native MNO key.
		// Best-effort — rememberResolvedMno never throws into this path.
		if ($chargeSent && $providerMnoKey !== '') {
			// $providerMnoKey is the DPO provider/execution key (e.g. "airtelke").
			// The identity cache stores the canonical MNO separately from the
			// provider-native key, so reverse-map it before writing the cache.
			//
			// Never write a provider-native key into the canonical MNO field.
			// If the key cannot be mapped, skip the write and log the anomaly.
			$canonicalMno = $this->mnoSuggestionValidatorService->canonicalMnoForOption(
				$providerMnoKey,
				$payment->getPhoneRegion(),
			);

			if ($canonicalMno !== null) {
				$this->phoneMnoResolver->rememberResolvedMno(
					$payment->getPhoneE164Digits(),
					$payment->getPhoneRegion(),
					$payment->getPhoneCountry(),
					$canonicalMno,
					$providerMnoKey,
				);
			} else {
				$this->logger->warning(
					'[MobileMoneyCharge] DPO provider key has no canonical MNO mapping; skipping identity cache write',
					[
						'providerKey' => $providerMnoKey,
						'region' => $payment->getPhoneRegion(),
					],
				);
			}
		}

		try {
			$this->verificationDispatcher->dispatchVerification($payment->getId());
		} catch (\Throwable $e) {
			$this->logger->error(
				'[Payment] verification dispatch failed',
				[
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
					'error' => $e->getMessage(),
				]
			);
		}

		return $this->responseFactory->buildExistingPaymentResponse($payment);
	}

	public function getMobileOptions(string $reference, string $country): array {
		$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			throw new RuntimeException('Mobile options only supported for DPO');
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			throw new RuntimeException('Cannot fetch options for completed payment');
		}

		$options = $this->mobileMoneyService->getMobileOptions($reference, $country);

		$meta = $payment->getProviderMetadataObject();

		$meta = $meta->with(
			updatedAt: $this->dateTimeHelper->nowImmutable(),
			selection: new SelectionDTO(
				required: true,
				options: $options,
				refreshedAt: time()
			)
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentRepository->update($payment);

		return $options;
	}

	/**
	 * Specific for deferred charge step in this case (DPO)
	 */
	private function validateOptionsSelection(array $options, string $providerMnoKey, string $country): void {
		foreach ($options as $option) {
			$optionMno = is_string($option['provider'] ?? null)
				? strtolower($option['provider'])
				: null;

			$optionCountry = is_string($option['country'] ?? null)
				? strtolower($option['country'])
				: null;

			if (
				$optionMno === strtolower($providerMnoKey)
				&& $optionCountry === strtolower($country)
			) {
				return;
			}
		}

		throw new RuntimeException('Invalid MNO selection');
	}

	private function getProviderPayload(
		PaymentMetadataDTO $meta,
	): ProviderPayloadDTO {
		return $meta->providerPayload
			?? new ProviderPayloadDTO();
	}
}
