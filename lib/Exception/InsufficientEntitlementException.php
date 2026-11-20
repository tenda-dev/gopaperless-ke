<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * Indicates that settlement could not find a usable entitlement.
 *
 * No entitlement was consumed. The failure requires intervention rather than
 * automatic retry, but settlement can be attempted again once the account
 * has sufficient entitlement coverage.
 */
class InsufficientEntitlementException extends \RuntimeException {
	public function __construct(string $message = 'No usable entitlement available.') {
		parent::__construct($message);
	}

	/**
	 * Machine-readable failure class, matching the vocabulary the
	 * reconciliation design (§4.C) uses for dead-letter classification.
	 */
	public function getFailureClass(): string {
		return 'insufficient_entitlement';
	}
}
