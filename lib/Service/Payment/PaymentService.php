<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentResultDTO;

/**
 * Core payment orchestration facade.
 *
 * Responsibilities:
 * - Expose a stable public API to controllers and background jobs.
 * - Delegate all domain work to focused, cohesive services.
 *
 * No business logic should be added here; this class only forwards calls.
 */
class PaymentService {
	public function __construct(
		private PaymentInitiationService $initiationService,
		private PaymentLifecycleService $lifecycleService,
		private PaymentCallbackService $callbackService,
		private MobileMoneyChargeService $mobileMoneyChargeService,
		private PaymentRepository $paymentRepository,
		private PaymentResponseFactory $responseFactory,
	) {
	}

	public function startPayment(StartPaymentDTO $dto): StartPaymentResultDTO|ExistingPaymentResultDTO {
		return $this->initiationService->startPayment($dto);
	}

	public function getPaymentStatus(string $reference): PaymentStatus {
		return $this->lifecycleService->getPaymentStatus($reference);
	}

	public function resumePayment(
		PaymentPurpose $purpose,
		?int $signRequestId = null,
		?string $signUuid = null,
		?string $productCode = null,
		?string $userId = null,
	): ?ExistingPaymentResultDTO {
		return $this->lifecycleService->resumePayment(
			$purpose,
			$signRequestId,
			$signUuid,
			$productCode,
			$userId,
		);
	}

	public function verifyPayment(string $reference): PaymentStatus {
		return $this->lifecycleService->verifyPayment($reference);
	}

	public function syncPaymentStatus(Payment $payment): void {
		$this->lifecycleService->syncPaymentStatus($payment);
	}

	public function queryPayment(string $reference): PaymentStatus {
		return $this->lifecycleService->queryPayment($reference);
	}

	public function handleDarajaCallback(array $payload): void {
		$this->callbackService->handleDarajaCallback($payload);
	}

	public function handleDpoCallback(array $payload): ?PaymentStatus {
		return $this->callbackService->handleDpoCallback($payload);
	}

	public function chargeMobile(
		string $reference,
		string $phone,
		?string $inputMno = null,
		?string $inputCountry = null,
	): ExistingPaymentResultDTO {
		return $this->mobileMoneyChargeService->chargeMobile(
			$reference,
			$phone,
			$inputMno,
			$inputCountry,
		);
	}

	public function getMobileOptions(string $reference, string $country): array {
		return $this->mobileMoneyChargeService->getMobileOptions($reference, $country);
	}

	public function assertPaymentOwnership(string $reference, string $userId): Payment {
		return $this->paymentRepository->assertPaymentOwnership($reference, $userId);
	}

	public function mapPaymentStatus(PaymentStatus $status): string {
		return $this->responseFactory->mapPaymentStatus($status);
	}

	public function getPaymentFailureReason(Payment $payment): ?string {
		return $this->responseFactory->getPaymentFailureReason($payment);
	}
}
