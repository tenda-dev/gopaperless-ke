<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Command;

use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Service\Messaging\RabbitMqService as MessagingRabbitMqService;
use OCA\Libresign\Service\Payment\PaymentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class PaymentVerificationWorker extends Command {
	public function __construct(
		private MessagingRabbitMqService $rabbitMqService,
		private PaymentMapper $paymentMapper,
		private PaymentService $paymentService,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('libresign:payments:worker')
			->setDescription(
				'Consumes payment verification messages from RabbitMQ.'
			);
	}

	protected function execute(
		InputInterface $input,
		OutputInterface $output,
	): int {

		$output->writeln(
			'<info>Starting payment verification worker...</info>'
		);

		$this->logger->info(
			'[PaymentWorker] started'
		);

		$this->rabbitMqService->consumePayments(
			function (int $paymentId): void {

				$this->processPayment(
					$paymentId
				);
			}
		);

		return Command::SUCCESS;
	}

	/**
	 * Process a payment verification message from RabbitMQ.
	 *
	 * RESPONSIBILITY:
	 * - Loads payment state
	 * - Skips payments already in terminal states
	 * - Ensures verification is due
	 * - Delegates ALL payment lifecycle decisions to PaymentService
	 *
	 * IMPORTANT:
	 * This worker intentionally contains NO payment business logic.
	 *
	 * PaymentService owns:
	 * - Expiration
	 * - Verification
	 * - Retry scheduling
	 * - Finalisation
	 * - Failure handling
	 *
	 * FUTURE IMPROVEMENTS:
	 * - RabbitMQ delayed queues
	 * - Dead letter queues
	 * - Verification locking
	 */
	private function processPayment(int $paymentId): void {
		$this->logger->info(
			'[PaymentWorker] processing payment',
			[
				'paymentId' => $paymentId,
			]
		);

		try {

			/**
			 * Load payment.
			 */
			$payment = $this->paymentMapper->findById(
				$paymentId
			);

			if ($payment === null) {

				$this->logger->warning(
					'[PaymentWorker] payment not found',
					[
						'paymentId' => $paymentId,
					]
				);

				return;
			}

			if (!$payment->isReadyForVerification()) {

				$this->logger->info(
					'[PaymentWorker] payment not ready for verification',
					[
						'paymentId' => $paymentId,
						'status' => $payment->getPaymentStatus()->value,
					]
				);

				return;
			}

			// Skip if another worker already owns this payment.
			if (
				!$this->paymentMapper->tryLockVerification(
					$paymentId
				)
			) {

				$this->logger->info(
					'[PaymentWorker] payment already locked',
					[
						'paymentId' => $paymentId,
					]
				);

				return;
			}

			$payment = $this->paymentMapper->findById($paymentId);

			if ($payment === null) {
				$this->logger->warning(
					'[PaymentWorker] payment disappeared after locking',
					[
						'paymentId' => $paymentId,
					]
				);
				return;
			}

			/**
			 * Delegate payment lifecycle management.
			 *
			 * PaymentService handles:
			 * - expiration
			 * - provider verification
			 * - retry scheduling
			 * - success/failure transitions
			 */
			$this->paymentService->syncPaymentStatus(
				$payment
			);

			$this->logger->info(
				'[PaymentWorker] payment processed',
				[
					'paymentId' => $paymentId,
					'status' => $payment
						->getPaymentStatus()
						->value,
				]
			);
		} catch (Throwable $e) {

			$this->logger->error(
				'[PaymentWorker] failed',
				[
					'paymentId' => $paymentId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			throw $e;
		}
	}
}
