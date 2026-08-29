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

		$mno = null;
		$country = null;

		if ($inputMno && $inputCountry) {
			$mno = strtolower($inputMno);
			$country = strtolower($inputCountry);
		} else {
			$mno = strtolower($suggested->mno ?? '');
			$country = strtolower($suggested->country ?? '');
		}

		if ($hasOptions) {
			$this->validateOptionsSelection($options, $mno, $country);
		} elseif (!$mno || !$country) {
			throw new RuntimeException('Missing MNO selection');
		}

		$selected = new SelectedMnoDTO($mno, $country);

		$amount = $this->amountResolver->toMajorUnits(
			$displayAmountMinor,
			$displayCurrency
		);

		$result = $this->mobileMoneyService->charge(
			new MobileMoneyChargeDTO(
				providerReference: $reference,
				phone: $phone,
				mno: $mno,
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
		// been dispatched and metadata persisted. MNO identity only (never the
		// rail); best-effort — rememberResolvedMno never throws into this path.
		if ($chargeSent && $mno !== '') {
			$this->phoneMnoResolver->rememberResolvedMno(
				$payment->getPhoneE164Digits(),
				$payment->getPhoneRegion(),
				$payment->getPhoneCountry(),
				$mno,
			);
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
	private function validateOptionsSelection(array $options, string $mno, string $country): void {
		foreach ($options as $option) {
			$optionMno = is_string($option['provider'] ?? null)
				? strtolower($option['provider'])
				: null;

			$optionCountry = is_string($option['country'] ?? null)
				? strtolower($option['country'])
				: null;

			if (
				$optionMno === strtolower($mno)
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
