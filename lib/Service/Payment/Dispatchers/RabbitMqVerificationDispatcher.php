<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment\Dispatchers;

use OCA\Libresign\Service\Messaging\RabbitMqService;
use OCA\Libresign\Service\Payment\Interfaces\IVerificationDispatcher;
use Psr\Log\LoggerInterface;

/**
 * RabbitMQ implementation of verification dispatching.
 *
 * RESPONSIBILITY:
 * - Publish payment verification requests to RabbitMQ
 *
 * USED FOR:
 * - Production environments
 * - High-throughput payment verification
 * - Horizontal scaling with multiple workers
 *
 * IMPORTANT:
 * This class only schedules verification work.
 * Actual payment verification happens inside PaymentVerificationWorker.
 */
class RabbitMqVerificationDispatcher
	implements IVerificationDispatcher
{
	public function __construct(
		private RabbitMqService $rabbitMqService,
		private LoggerInterface $logger,
	) {}

	/**
	 * Dispatch payment verification via RabbitMQ.
	 */
	public function dispatchVerification(
		int $paymentId
	): void {

		$this->logger->info(
			'[RabbitMQDispatcher] dispatching verification',
			[
				'paymentId' => $paymentId,
			]
		);

		$this->rabbitMqService->publishPaymentVerification(
			$paymentId
		);
	}
}
