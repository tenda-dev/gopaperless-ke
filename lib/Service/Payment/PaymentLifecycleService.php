<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\Dispatchers\VerificationDispatcherFactory;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use Psr\Log\LoggerInterface;

/**
 * Read, resume, verify, sync, query and expire payments.
 *
 * This service does NOT initiate payments; it only transitions and
 * inspects existing payment state.
 */
class PaymentLifecycleService {
	/**
	 * Operational payment expiry window.
	 */
	private const PAYMENT_EXPIRY_SECONDS = 12 * 60; // 12 minutes

	/**
	 * How long an already-charged async payment may stay PENDING
	 * before a user retry is allowed to start a fresh attempt.
	 */
	private const PAYMENT_STALE_FOR_RETRY_SECONDS = 90;

	public function __construct(
		private PaymentRepository $paymentRepository,
		private PaymentFinalizerService $finalizer,
		private VerificationService $verificationService,
		private PaymentDateTimeHelper $dateTimeHelper,
		private LoggerInterface $logger,
		private PaymentResponseFactory $responseFactory,
		private VerificationDispatcherFactory $verificationDispatcher,
	) {
	}

	/**
	 * Get payment status (READ-ONLY except for local expiry).
	 */
	public function getPaymentStatus(string $reference): PaymentStatus {
		$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		if ($this->hasPaymentExpired($payment)) {
			$payment->setPaymentStatus(PaymentStatus::FAILED);

			$meta = $payment->getProviderMetadataObject();
			$meta = $meta->with(
				providerError: [
					'type' => 'expired',
					'timestamp' => $this->dateTimeHelper->now(),
				]
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentRepository->update($payment);

			return PaymentStatus::FAILED;
		}

		return $payment->getPaymentStatus();
	}

	/**
	 * Restore an existing payment session for frontend hydration.
	 */
	public function resumePayment(
		PaymentPurpose $purpose,
		?int $signRequestId = null,
		?string $signUuid = null,
		?string $productCode = null,
		?string $userId = null,
	): ?ExistingPaymentResultDTO {
		$payment = match ($purpose) {
			PaymentPurpose::SIGN_REQUEST
			=> $signRequestId !== null
				? $this->paymentRepository->findLatestPendingByTransactionId($signRequestId)
				: null,

			PaymentPurpose::CREDIT_PURCHASE
			=> $userId !== null && $productCode !== null
				? $this->paymentRepository->findLatestPendingCreditPurchaseByUserId(
					$userId,
					$productCode,
				)
				: null,
		};

		if (!$payment) {
			return null;
		}

		if (
			$purpose === PaymentPurpose::SIGN_REQUEST
			&& $payment->getTransactionReference() !== $signUuid
		) {
			return null;
		}

		if (
			$userId !== null
			&& $payment->getUserId() !== null
			&& $payment->getUserId() !== $userId
		) {
			return null;
		}

		if ($this->hasPaymentExpired($payment)) {
			$this->expirePayment($payment);
			return null;
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return null;
		}

		return $this->responseFactory->buildExistingPaymentResponse($payment);
	}

	/**
	 * Verify payment after redirect (LIGHT CHECK).
	 */
	public function verifyPayment(string $reference): PaymentStatus {
		$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			return $payment->getPaymentStatus();
		}

		try {
			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$reference
			);

			if ($status === 'SUCCESS') {
				$this->finalizer->finalisePayment($payment);
				return PaymentStatus::PAID;
			}

			if ($status === 'FAILED') {
				$payment->setUpdatedAt($this->dateTimeHelper->now());
				$payment->setPaymentStatus(PaymentStatus::FAILED);
				$this->paymentRepository->update($payment);

				return PaymentStatus::FAILED;
			}

			return PaymentStatus::PENDING;
		} catch (\Throwable $e) {
			$this->logger->warning('[Payment] verifyPayment failed (non-blocking)', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			return PaymentStatus::PENDING;
		}
	}

	/**
	 * Synchronise payment status with provider (BACKGROUND JOB ONLY).
	 *
	 * @throws \Throwable
	 */
	public function syncPaymentStatus(Payment $payment): void {
		$this->logger->info('[Payment] syncPaymentStatus start', [
			'paymentId' => $payment->getId(),
			'provider' => $payment->getProvider(),
			'reference' => $payment->getProviderReference(),
			'status' => $payment->getStatus(),
		]);

		try {
			if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
				$payment->unlockVerification();
				$this->paymentRepository->update($payment);
				return;
			}

			if ($this->hasPaymentExpired($payment)) {
				$this->logger->info('[Payment] payment expired during reconciliation', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$payment->setPaymentStatus(PaymentStatus::EXPIRED);

				$meta = $payment->getProviderMetadataObject();

				$providerError = [
					'type' => 'expired',
					'source' => 'background_verification',
					'timestamp' => $this->dateTimeHelper->now(),
				];

				if ($meta) {
					$payment->setUpdatedAt($this->dateTimeHelper->now());
					$meta = $this->appendProviderError($meta, $providerError);
					$payment->setProviderMetadataObject($meta);
				}

				$payment->unlockVerification();
				$this->paymentRepository->update($payment);

				return;
			}

			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$payment->getProviderReference()
			);

			$this->logger->info('[Payment] verification result', [
				'paymentId' => $payment->getId(),
				'reference' => $payment->getProviderReference(),
				'verificationStatus' => $status,
			]);

			$payment->setVerificationStatus($status);
			$payment->setVerificationLastCheckedAt($this->dateTimeHelper->now());

			if ($status === 'SUCCESS') {
				$this->logger->info('[Payment] payment verified successfully', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$this->finalizer->finalisePayment($payment);
				$payment->unlockVerification();

				$this->paymentRepository->update($payment);
				return;
			}

			if ($status === 'FAILED') {
				$this->logger->warning('[Payment] payment verification failed', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
				]);

				$payment->setUpdatedAt($this->dateTimeHelper->now());
				$payment->setPaymentStatus(PaymentStatus::FAILED);
				$payment->unlockVerification();

				$this->paymentRepository->update($payment);
				return;
			}

			if ($payment->shouldRetry()) {
				$newRetry = $payment->getVerificationRetryCount() + 1;

				$payment->setVerificationRetryCount($newRetry);
				$payment->scheduleNextVerification($newRetry);
				$payment->unlockVerification();

				$this->logger->info('[Payment] scheduling retry', [
					'paymentId' => $payment->getId(),
					'reference' => $payment->getProviderReference(),
					'retry' => $newRetry,
					'nextVerificationAt' => $payment->getNextVerificationAt(),
				]);

				$payment->setUpdatedAt($this->dateTimeHelper->now());
				$this->paymentRepository->update($payment);

				$this->logger->info(
					'[Payment] dispatching retry verification',
					[
						'paymentId' => $payment->getId(),
						'retry' => $newRetry,
					]
				);

				$this->verificationDispatcher->dispatchVerification(
					$payment->getId()
				);
				return;
			}

			$payment->setPaymentStatus(PaymentStatus::FAILED);
			$payment->unlockVerification();
			$payment->setUpdatedAt($this->dateTimeHelper->now());

			$this->paymentRepository->update($payment);
		} catch (\Throwable $e) {
			$this->logger->error('[VerificationService] verifyStatus failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			$payment->setLastErrorCode('VERIFY_FAILED');
			$payment->setLastErrorMessage($e->getMessage());
			$payment->setLastErrorAt($this->dateTimeHelper->now());
			$payment->setUpdatedAt($this->dateTimeHelper->now());

			$payment->unlockVerification();
			$this->paymentRepository->update($payment);

			throw $e;
		}
	}

	/**
	 * Query payment status using provider fallback (Daraja STK query).
	 */
	public function queryPayment(string $reference): PaymentStatus {
		$payment = $this->paymentRepository->fetchPaymentByProviderReference($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DARAJA) {
			return $payment->getPaymentStatus();
		}

		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		try {
			$result = $this->verificationService->query(
				$payment->getProviderEnum(),
				$reference
			);

			$meta = $payment->getProviderMetadataObject();

			$failureReason = 'generic_failure';

			switch ($result['status']) {
				case 'SUCCESS':
					$providerPayload = $this->getProviderPayload($meta)
						->withQuery($result);

					$meta = $meta->with(
						updatedAt: $this->dateTimeHelper->nowImmutable(),
						providerPayload: $providerPayload
					);

					$payment->setProviderMetadataObject($meta);
					$this->finalizer->finalisePayment($payment);
					return PaymentStatus::PAID;

				case 'FAILED':
					$payment->setPaymentStatus(PaymentStatus::FAILED);
					$failureReason = $result['reason'] ?? 'generic_failure';
					break;

				case 'CANCELLED':
					$payment->setPaymentStatus(PaymentStatus::CANCELLED);
					$failureReason = $result['reason'] ?? 'cancelled';
					break;

				case 'PENDING':
				default:
					return PaymentStatus::PENDING;
			}

			$providerPayload = $this->getProviderPayload($meta)
				->withQuery($result);

			$meta = $meta->with(
				updatedAt: $this->dateTimeHelper->nowImmutable(),
				providerPayload: $providerPayload,
				providerError: [
					'type' => $payment->getPaymentStatus() === PaymentStatus::CANCELLED ? 'cancelled' : 'failed',
					'reason' => $failureReason,
					'source' => 'daraja_query',
					'timestamp' => $this->dateTimeHelper->now(),
				]
			);

			$payment->setProviderMetadataObject($meta);

			$this->paymentRepository->update($payment);

			return $payment->getPaymentStatus();
		} catch (\Throwable $e) {
			$this->logger->error('Daraja query failed', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			return PaymentStatus::PENDING;
		}
	}

	public function expirePayment(Payment $payment): void {
		$payment->setPaymentStatus(PaymentStatus::FAILED);

		$meta = $payment->getProviderMetadataObject();

		$meta = $meta->with(
			updatedAt: $this->dateTimeHelper->nowImmutable(),
			providerError: [
				'type' => 'expired',
				'reason' => 'expired',
				'timestamp' => $this->dateTimeHelper->now(),
			]
		);

		$payment->setProviderMetadataObject($meta);

		$this->paymentRepository->update($payment);
	}

	public function hasPaymentExpired(Payment $payment): bool {
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return false;
		}

		$createdAt = $payment->getCreatedAtImmutable();

		if ($createdAt === null) {
			return true;
		}

		$now = $this->dateTimeHelper->nowImmutable();

		$diffInSeconds = $now->getTimestamp() - $createdAt->getTimestamp();

		return $diffInSeconds > self::PAYMENT_EXPIRY_SECONDS;
	}

	public function isPendingStaleForRetry(Payment $payment): bool {
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return false;
		}

		$meta = $payment->getProviderMetadataObject();

		if (!$meta->alreadyCharged) {
			return false;
		}

		$createdAt = $payment->getCreatedAtImmutable();

		if ($createdAt === null) {
			return true;
		}

		$now = $this->dateTimeHelper->nowImmutable();
		$diffInSeconds = $now->getTimestamp() - $createdAt->getTimestamp();

		return $diffInSeconds > self::PAYMENT_STALE_FOR_RETRY_SECONDS;
	}

	private function appendProviderError(
		PaymentMetadataDTO $meta,
		array $error,
	): PaymentMetadataDTO {
		return $meta->with(
			updatedAt: $this->dateTimeHelper->nowImmutable(),
			providerError: [
				...($meta->providerError ?? []),
				...$error,
			]
		);
	}

	private function getProviderPayload(
		PaymentMetadataDTO $meta,
	): ProviderPayloadDTO {
		return $meta->providerPayload
			?? new ProviderPayloadDTO();
	}
}
