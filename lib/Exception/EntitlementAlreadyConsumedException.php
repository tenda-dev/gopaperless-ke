<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * Indicates that entitlement settlement was skipped because it was already
 * completed for the sign request.
 *
 * This is an expected idempotent outcome, not a settlement failure.
 * The distinct exception allows callers to distinguish duplicate settlement
 * attempts from genuine failures without changing existing control flow.
 */
class EntitlementAlreadyConsumedException extends \RuntimeException {
	/**
	 * Returns the machine-readable settlement outcome.
	 */
	public function getFailureClass(): string {
		return 'already_consumed';
	}
}
