<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\ExtendedAccount\ExtendedAccountService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Atomically finalise a successful payment.
 *
 * The whole operation (status update + access grant) runs inside a
 * single database transaction so it can never partially succeed. The
 * grant itself is purpose-dependent: certificate purchases extend the
 * account's certificate window; everything else grants a use-based
 * entitlement.
 */
class PaymentFinalizerService {
	public function __construct(
		private EntitlementService $entitlementService,
		private PaymentMapper $paymentMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private PaymentDateTimeHelper $dateTimeHelper,
		private ExtendedAccountService $extendedAccountService,
	) {
	}

	/**
	 * @throws \Throwable
	 */
	public function finalisePayment(Payment $payment): void {
		/**
		 * IDEMPOTENCY GUARD
		 *
		 * Payment providers may send duplicate callbacks.
		 * If already processed, exit early.
		 */
		if ($payment->getPaymentStatus() === PaymentStatus::PAID) {
			return;
		}

		/**
		 * CRITICAL TRANSACTION
		 *
		 * Ensures atomic consistency between:
		 * - Payment status update (financial state)
		 * - Entitlement creation (access state)
		 *
		 * MUST NOT partially succeed.
		 */
		$this->db->beginTransaction();

		try {
			$this->logger->info('[PAYMENT FINALISE] Before update', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
			]);
			$payment->setUpdatedAt($this->dateTimeHelper->now());
			$payment->setPaymentStatus(PaymentStatus::PAID);
			$payment->setPaidAt($this->dateTimeHelper->now());

			$this->paymentMapper->update($payment);

			/**
			 * Grant access from the successful payment.
			 *
			 * Branch on purpose: a certificate purchase extends the
			 * account's time-bound certificate window and grants NO
			 * use-based entitlement; signer payments and credit
			 * purchases grant a use-based entitlement as before.
			 */
			match ($payment->getPaymentPurpose()) {
				PaymentPurpose::CERTIFICATE_PURCHASE
				=> $this->extendedAccountService->renewCertificate(
					$payment->getUserId(),
				),

				default
				=> $this->entitlementService->create(
					$payment->getUserId(),
					$payment->getProductCode(),
					$payment->getProductUses(),
				),
			};

			$this->db->commit();

			$this->logger->info('[PAYMENT FINALISE] After update call', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
				'purpose' => $payment->getPaymentPurpose()->value,
			]);
		} catch (\Throwable $e) {
			/**
			 * Rollback ALL changes to prevent partial state
			 */
			$this->db->rollBack();

			$this->logger->error('[PAYMENT FINALISE] failed', [
				'exception' => $e,
				'paymentId' => $payment->getId(),
				'purpose' => $payment->getPaymentPurpose()->value,
			]);

			throw $e;
		}
	}
}
