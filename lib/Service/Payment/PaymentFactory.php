<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\DTO\FxEngineResultDTO;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;

/**
 * Creates and persists a Payment entity for a new attempt.
 *
 * All FX-related fields are captured at creation time so the
 * provider call uses the same locked rate.
 */
class PaymentFactory {
	public function __construct(
		private PaymentMapper $paymentMapper,
		private PaymentDateTimeHelper $dateTimeHelper,
	) {
	}

	/**
	 * Create and persist a new Payment with FX fields populated.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \InvalidArgumentException
	 */
	public function createPayment(
		StartPaymentDTO $dto,
		MnoRoutingResultDTO $route,
		FxEngineResultDTO $fxEngineResult,
		int $amountMinor,
		int $unitAmount,
		int $uses,
		string $currency,
		?string $e164,
		string $paymentAttemptId,
	): Payment {
		$payment = new Payment();
		$payment->setPaymentAttemptId($paymentAttemptId);
		$payment->setPaymentPurpose($dto->purpose);
		$payment->setTransactionId($dto->signRequestId);
		$payment->setTransactionReference($dto->signUuid);
		$payment->setAmount($amountMinor);
		$payment->setCurrency($currency);
		$payment->setUserId($dto->userId);
		$payment->setPhoneE164Digits($e164);
		$payment->setPhoneRegion($route->region);
		$payment->setPhoneCountry($route->country);
		$payment->setProductCode($dto->productCode);
		$payment->setProductUses($uses);
		$payment->setQuantity($dto->quantity);
		$payment->setUnitAmount($unitAmount);
		$payment->setPaymentStatus(PaymentStatus::PENDING);
		$payment->setProvider($route->preferredProvider->value);
		$payment->setCreatedAt($this->dateTimeHelper->now());

		$payment->setDisplayAmount($fxEngineResult->displayAmount);
		$payment->setDisplayCurrency($fxEngineResult->displayCurrency);
		$payment->setFxRate((string)$fxEngineResult->fxRate);
		$payment->setFxRateSource($fxEngineResult->fxRateSource);
		$payment->setFxRateLockedAt($fxEngineResult->fxRateLockedAt->format(DATE_ATOM));

		$payment->validate();
		$this->paymentMapper->insert($payment);

		return $payment;
	}
}
