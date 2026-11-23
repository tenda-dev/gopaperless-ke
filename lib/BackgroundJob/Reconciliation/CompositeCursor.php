<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob\Reconciliation;

/**
 * Represents a position in the reconciliation order `(signed ASC, id ASC)`.
 *
 * The ID provides a stable tie-breaker for requests with the same signing
 * timestamp. Both values must be persisted together to prevent pagination
 * from skipping or revisiting records at timestamp boundaries.
 */
final class CompositeCursor {
	public function __construct(
		public readonly int $signed,
		public readonly int $id,
	) {
	}
}
