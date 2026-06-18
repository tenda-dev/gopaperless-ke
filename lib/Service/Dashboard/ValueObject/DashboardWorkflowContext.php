<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Dashboard\ValueObject;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\SignRequest;

final class DashboardWorkflowContext {
	/**
	 * @param SignRequest[] $signRequests
	 */
	public function __construct(
		public readonly File $file,
		public readonly ?string $requesterName,
		public readonly array $signRequests,
		public readonly ?Payment $payment,
		public readonly DashboardParticipantContext $participantContext,
		public readonly bool $isOwner,
		public readonly bool $isCompleted,
		public readonly bool $isDraft,
	) {
	}
}
