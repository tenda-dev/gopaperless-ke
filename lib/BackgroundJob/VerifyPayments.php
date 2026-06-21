<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\Payment\PaymentService;
use OCA\Libresign\Db\PaymentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class VerifyPayments extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private PaymentMapper $paymentMapper,
		private PaymentService $paymentService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Run every 1 minute (tuned from 15s to reduce DB load)
		$this->setInterval(30);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function run($argument): void
	{
		$this->logger->info('[VerifyPayments] job started');

		// small batch → controlled load
		$payments = $this->paymentMapper->findPendingForVerification(100);


		$this->logger->info('[VerifyPayments] payments found', [
			'count' => count($payments),
		]);

		foreach ($payments as $payment) {

			try {

				// safety guard (should already be filtered but double-safe)
				if (!$payment->isReadyForVerification()) {
					continue;
				}

				// lock
				$payment->lockVerification();
				$this->paymentMapper->update($payment);

				$this->logger->info('[VerifyPayments] processing payment', [
					'paymentId' => $payment->getId(),
					'provider' => $payment->getProvider(),
					'status' => $payment->getStatus(),
					'reference' => $payment->getProviderReference(),
					'retryCount' => $payment->getVerificationRetryCount(),
				]);

				// delegate actual verification
				$this->paymentService->syncPaymentStatus($payment);
			} catch (\Throwable $e) {

				$this->logger->error('[VerifyPayments] failed', [
					'paymentId' => $payment->getId(),
					'provider' => $payment->getProvider(),
					'reference' => $payment->getProviderReference(),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'exception' => get_class($e),
				]);

				// fallback safety unlock (avoid deadlocks)
				try {
					$payment->unlockVerification();
					$this->paymentMapper->update($payment);
				} catch (\Throwable) {
					// swallow — last resort
				}
			}
		}
	}
}
