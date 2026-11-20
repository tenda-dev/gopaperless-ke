<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * Signals that an entitlement candidate became unusable before it could be
 * secured for settlement.
 *
 * The candidate is revalidated after acquiring its lock. If it is no longer
 * usable, settlement rolls back the transaction and retries with that
 * entitlement excluded, releasing existing locks before selecting another.
 *
 * This exception is an internal retry signal and must not reach controller
 * responses.
 */
class StaleEntitlementException extends \RuntimeException {
	public function __construct(
		private int $entitlementId,
		private int $candidateCount,
	) {
		parent::__construct(sprintf(
			'Entitlement %d was no longer usable once locked; %d candidate(s) were available for selection.',
			$entitlementId,
			$candidateCount,
		));
	}

	public function getEntitlementId(): int {
		return $this->entitlementId;
	}

	/**
	 * Returns the number of candidates available when this attempt began.
	 *
	 * Used to bound retries so settlement does not repeatedly attempt the same
	 * exhausted candidate set.
	 */
	public function getCandidateCount(): int {
		return $this->candidateCount;
	}
}
