<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\Dispatchers;

use OCA\Libresign\BackgroundJob\VerifySinglePayment;
use OCA\Libresign\Service\Payment\Interfaces\IVerificationDispatcher;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud background job implementation of verification dispatching.
 *
 * RESPONSIBILITY:
 * - Schedule verification using Nextcloud background jobs
 *
 * USED FOR:
 * - Installations without RabbitMQ
 * - Simpler deployments
 *
 * IMPORTANT:
 * Background jobs are NOT suitable for high-throughput
 * payment systems but provide a reasonable default.
 */
class NextcloudVerificationDispatcher implements IVerificationDispatcher {
	public function __construct(
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Dispatch payment verification using Nextcloud jobs.
	 */
	public function dispatchVerification(
		int $paymentId,
	): void {

		$this->logger->info(
			'[NextcloudDispatcher] dispatching verification',
			[
				'paymentId' => $paymentId,
			]
		);

		$this->jobList->add(
			VerifySinglePayment::class,
			[
				'paymentId' => $paymentId,
			]
		);
	}
}
