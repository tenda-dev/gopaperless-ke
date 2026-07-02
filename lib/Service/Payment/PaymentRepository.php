<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hydrated payment lookup and ownership assertions.
 *
 * All read operations re-fetch the entity by ID to work around
 * Nextcloud QBMapper partial-hydration quirks.
 */
class PaymentRepository {
	public function __construct(
		private PaymentMapper $paymentMapper,
		private LoggerInterface $logger,
	) {
	}

	public function update(Payment $payment): void {
		$this->paymentMapper->update($payment);
	}

	public function findByAttemptId(string $attemptId): ?Payment {
		$payment = $this->paymentMapper->findByAttemptId($attemptId);

		return $payment !== null ? $this->hydrate($payment) : null;
	}

	public function findLatestPaidByTransactionId(int $transactionId): ?Payment {
		$payment = $this->paymentMapper->findLatestPaidByTransactionId($transactionId);

		return $payment !== null ? $this->hydrate($payment) : null;
	}

	public function findLatestPendingByTransactionId(int $transactionId): ?Payment {
		$payment = $this->paymentMapper->findLatestPendingByTransactionId($transactionId);

		return $payment !== null ? $this->hydrate($payment) : null;
	}

	public function findLatestPendingCreditPurchaseByUserId(
		string $userId,
		string $productCode,
	): ?Payment {
		$payment = $this->paymentMapper->findLatestPendingCreditPurchaseByUserId(
			$userId,
			$productCode,
		);

		return $payment !== null ? $this->hydrate($payment) : null;
	}

	/**
	 * Fetch a payment by provider reference.
	 *
	 * @throws RuntimeException when the payment is not found.
	 */
	public function fetchPaymentByProviderReference(string $reference): Payment {
		$payment = $this->paymentMapper->findByProviderReference($reference);

		if (!$payment) {
			$this->logger->error('[PaymentRepository] Payment not found', [
				'reference' => $reference,
			]);

			throw new RuntimeException('Payment not found');
		}

		return $this->hydrate($payment);
	}

	/**
	 * Fetch the latest pending payment for a transaction.
	 *
	 * @throws RuntimeException when the payment is not found.
	 */
	public function fetchByTransactionId(int $signRequestId): Payment {
		$payment = $this->paymentMapper->findLatestPendingByTransactionId($signRequestId);

		if (!$payment) {
			$this->logger->error('[PaymentRepository] Payment not found', [
				'sign_request_id' => $signRequestId,
			]);

			throw new RuntimeException('Payment not found');
		}

		return $this->hydrate($payment);
	}

	/**
	 * Verify that the authenticated user owns the payment.
	 *
	 * @throws RuntimeException if the payment does not exist or the user is not the owner.
	 */
	public function assertPaymentOwnership(string $reference, string $userId): Payment {
		$payment = $this->fetchPaymentByProviderReference($reference);

		$paymentUserId = $payment->getUserId();
		if ($paymentUserId !== null && $paymentUserId !== $userId) {
			throw new RuntimeException('Access Denied');
		}

		return $payment;
	}

	/**
	 * ENTITY REHYDRATION (Nextcloud ORM quirk)
	 *
	 * Re-fetching ensures a fully hydrated entity after initial lookup.
	 */
	private function hydrate(Payment $payment): Payment {
		$hydrated = $this->paymentMapper->findById($payment->getId());

		if (!$hydrated) {
			throw new RuntimeException('Payment not found');
		}

		return $hydrated;
	}
}
