<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\Interfaces;

/**
 * Dispatches payment verification work.
 *
 * RESPONSIBILITY:
 * - Schedule payment verification processing
 * - Hide implementation details from PaymentService
 *
 * IMPLEMENTATIONS:
 * - RabbitMqVerificationDispatcher
 * - NextcloudVerificationDispatcher
 *
 * IMPORTANT:
 * This interface does NOT perform verification.
 * It only delegates work to the appropriate execution mechanism.
 */
interface IVerificationDispatcher
{
	/**
	 * Dispatch payment verification.
	 *
	 * IMPLEMENTATIONS MAY:
	 * - Publish to RabbitMQ
	 * - Schedule Nextcloud background jobs
	 * - Use another queue system in future
	 */
	public function dispatchVerification(
		int $paymentId
	): void;
}
