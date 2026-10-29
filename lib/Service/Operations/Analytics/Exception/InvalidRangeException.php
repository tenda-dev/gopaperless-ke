<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Exception;

/**
 * A request parameter was rejected. `reason` is the stable machine code
 * returned to the client as `{error: <reason>}` with HTTP 400.
 */
final class InvalidRangeException extends \InvalidArgumentException {
	public const INVALID_RANGE = 'invalid_range';
	public const RANGE_TOO_LARGE = 'range_too_large';
	public const INVALID_PARAMS = 'invalid_params';

	public function __construct(public readonly string $reason) {
		parent::__construct($reason);
	}
}
