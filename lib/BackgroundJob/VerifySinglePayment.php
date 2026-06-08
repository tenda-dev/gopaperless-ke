<?php

declare(strict_types=1);

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\Payment\PaymentService;
use OCA\Libresign\Db\PaymentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class VerifySinglePayment extends QueuedJob
{
	public function __construct(
		ITimeFactory $time,
		private PaymentMapper $mapper,
		private PaymentService $paymentService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void
	{
		$this->logger->info('[VerifySinglePayment] job started');

		$paymentId = (int)($argument['paymentId'] ?? 0);

		if ($paymentId <= 0) {
			$this->logger->info('[VerifySinglePayment] Invalid payment ID');
			return;
		}

		$payment = $this->mapper->findById(
			$paymentId
		);

		if ($payment === null) {
			$this->logger->info('[VerifySinglePayment] Payment not found');
			return;
		}

		$this->logger->info(
			'[VerifySinglePayment] Attempting verification',
			['paymentId' => $paymentId]
		);

		if (!$payment->isReadyForVerification()) {

			$this->logger->info(
				'[VerifySinglePayment] payment not ready',
				[
					'paymentId' => $paymentId,
					'status' => $payment->getPaymentStatus()->value,
				]
			);

			return;
		}

		// Try to acquire lock for verification
		if (
			!$this->mapper->tryLockVerification(
				$paymentId
			)
		) {
			$this->logger->info('[VerifySinglePayment] Failed to acquire lock');
			return;
		}

		$payment = $this->mapper->findById(
			$paymentId
		);

		if ($payment === null) {
			$this->logger->info('[VerifySinglePayment] Payment not found');
			return;
		}
		$this->logger->info(
			'[VerifySinglePayment] Lock acquired, syncing status',
			['paymentId' => $paymentId]
		);
		$this->paymentService
			->syncPaymentStatus(
				$payment
			);
	}
}
