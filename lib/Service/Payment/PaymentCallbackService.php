<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use Psr\Log\LoggerInterface;

/**
 * Handles asynchronous provider callbacks.
 *
 * Both Daraja and DPO callbacks are resolved through the repository,
 * validated for idempotency, and then either finalised or failed.
 */
class PaymentCallbackService {
	public function __construct(
		private PaymentRepository $paymentRepository,
		private PaymentFinalizerService $finalizer,
		private PaymentDateTimeHelper $dateTimeHelper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Handle Daraja callback.
	 *
	 * Daraja Result codes:
	 * - 0    → SUCCESS
	 * - 1032 → CANCELLED (user cancelled the STK push)
	 * - 1    → FAILED (insufficient funds)
	 * - 1037 → FAILED (timeout — user didn't respond)
	 * - any other non-zero → FAILED
	 *
	 * @throws \Throwable
	 */
	public function handleDarajaCallback(array $payload): void {
		$reference = $payload['CheckoutRequestID'] ?? null;
		$resultCode = $payload['ResultCode'] ?? null;
		$resultDesc = $payload['ResultDesc'] ?? null;

		if (!$reference) {
			$this->logger->error('[Daraja Callback] Missing CheckoutRequestID');
			return;
		}

		if ($resultCode === null) {
			$this->logger->error('[Daraja Callback] Missing ResultCode', [
				'reference' => $reference,
			]);
			return;
		}

		try {
			$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

			$this->logger->info('[Daraja Callback] Received', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[Daraja Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return;
			}

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerPayload: $this->getProviderPayload($meta)->withCallback($payload)
			);
			$payment->setProviderMetadataObject($meta);

			if ((int)$resultCode === 0) {
				$metadata = $this->extractMetadata($payload);

				$this->logger->info('[Daraja Callback] Payment SUCCESS', [
					'reference' => $reference,
					'mpesaReceipt' => $metadata['MpesaReceiptNumber'] ?? null,
				]);

				$payment->setVerificationStatus('SUCCESS');
				$payment->setVerificationLastCheckedAt($this->dateTimeHelper->now());

				$this->finalizer->finalisePayment($payment);
				return;
			}

			if ((int)$resultCode === 1032) {
				$this->logger->info('[Daraja Callback] Payment CANCELLED by user', [
					'reference' => $reference,
					'resultDesc' => $resultDesc,
				]);

				$payment->setUpdatedAt($this->dateTimeHelper->now());
				$payment->setPaymentStatus(PaymentStatus::CANCELLED);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					updatedAt: $this->dateTimeHelper->nowImmutable(),
					providerError: [
						'type' => 'cancelled',
						'reason' => DarajaService::mapResultCodeToReason((int)$resultCode),
						'resultCode' => $resultCode,
						'resultDesc' => $resultDesc,
						'source' => 'daraja_callback',
						'timestamp' => $this->dateTimeHelper->now(),
					]
				);
				$payment->setProviderMetadataObject($meta);

				$this->paymentRepository->update($payment);
				return;
			}

			$this->logger->warning('[Daraja Callback] Payment FAILED', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'resultDesc' => $resultDesc,
			]);

			$payment->setUpdatedAt($this->dateTimeHelper->now());
			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				updatedAt: $this->dateTimeHelper->nowImmutable(),
				providerError: [
					'type' => 'failed',
					'reason' => DarajaService::mapResultCodeToReason((int)$resultCode),
					'resultCode' => $resultCode,
					'resultDesc' => $resultDesc,
					'source' => 'daraja_callback',
					'timestamp' => $this->dateTimeHelper->now(),
				]
			);
			$payment->setProviderMetadataObject($meta);

			$this->paymentRepository->update($payment);
		} catch (\Throwable $e) {
			$this->logger->error('[Daraja Callback] Processing failed', [
				'reference' => $reference,
				'resultCode' => $resultCode,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			throw $e;
		}
	}

	/**
	 * Handle DPO callback.
	 *
	 * DPO Result codes:
	 * - 000 → SUCCESS
	 * - 901 → FAILED (payment failed)
	 * - 902 → FAILED (payment declined)
	 * - 904 → CANCELLED (user cancelled)
	 *
	 * @throws \Throwable
	 */
	public function handleDpoCallback(array $payload): ?PaymentStatus {
		$reference = $payload['TransactionToken'] ?? null;
		$result = (string)($payload['Result'] ?? '');

		if (!$reference) {
			$this->logger->error('[DPO Callback] Missing TransactionToken');
			return null;
		}

		if ($result === '') {
			$this->logger->error('[DPO Callback] Missing Result code', [
				'reference' => $reference,
			]);
			return null;
		}

		try {
			$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

			$this->logger->info('[DPO Callback] Received', [
				'reference' => $reference,
				'result' => $result,
				'currentStatus' => $payment->getPaymentStatus()->value,
			]);

			if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
				$this->logger->info('[DPO Callback] Duplicate ignored — already resolved', [
					'reference' => $reference,
					'status' => $payment->getPaymentStatus()->value,
				]);
				return $payment->getPaymentStatus();
			}

			if ($result === '000') {
				$this->logger->info('[DPO Callback] Payment SUCCESS', [
					'reference' => $reference,
					'amount' => $payload['TransactionAmount'] ?? null,
					'currency' => $payload['TransactionCurrency'] ?? null,
				]);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					providerPayload: $this->getProviderPayload($meta)->withCallback($payload)
				);
				$payment->setProviderMetadataObject($meta);
				$payment->setVerificationStatus('SUCCESS');
				$payment->setVerificationLastCheckedAt($this->dateTimeHelper->now());

				$this->finalizer->finalisePayment($payment);
				return $payment->getPaymentStatus();
			}

			if ($result === '904') {
				$this->logger->info('[DPO Callback] Payment CANCELLED by user', [
					'reference' => $reference,
				]);

				$payment->setUpdatedAt($this->dateTimeHelper->now());
				$payment->setPaymentStatus(PaymentStatus::CANCELLED);

				$meta = $payment->getProviderMetadataObject();
				$meta = $meta->with(
					updatedAt: $this->dateTimeHelper->nowImmutable(),
					providerError: [
						'type' => 'cancelled',
						'result' => $result,
						'source' => 'dpo_callback',
						'timestamp' => $this->dateTimeHelper->now(),
					]
				);
				$payment->setProviderMetadataObject($meta);

				$this->paymentRepository->update($payment);
				return $payment->getPaymentStatus();
			}

			$this->logger->warning('[DPO Callback] Payment FAILED', [
				'reference' => $reference,
				'result' => $result,
			]);

			$payment->setUpdatedAt($this->dateTimeHelper->now());
			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				updatedAt: $this->dateTimeHelper->nowImmutable(),
				providerError: [
					'type' => 'failed',
					'result' => $result,
					'source' => 'dpo_callback',
					'timestamp' => $this->dateTimeHelper->now(),
				]
			);
			$payment->setProviderMetadataObject($meta);

			$this->paymentRepository->update($payment);
		} catch (\Throwable $e) {
			$this->logger->error('[DPO Callback] Processing failed', [
				'reference' => $reference,
				'result' => $result,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			throw $e;
		}

		return $payment->getPaymentStatus();
	}

	/**
	 * Extract Daraja metadata into key-value map.
	 */
	private function extractMetadata(array $callback): array {
		$items = $callback['CallbackMetadata']['Item'] ?? [];

		$result = [];

		foreach ($items as $item) {
			$name = $item['Name'] ?? null;
			$value = $item['Value'] ?? null;

			if ($name) {
				$result[$name] = $value;
			}
		}

		return $result;
	}

	private function getProviderPayload(
		PaymentMetadataDTO $meta,
	): ProviderPayloadDTO {
		return $meta->providerPayload
			?? new ProviderPayloadDTO();
	}
}
