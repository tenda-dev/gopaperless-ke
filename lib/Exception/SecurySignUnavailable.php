<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * SecurySign cannot sign right now: unreachable, erroring or refusing to start.
 * SignFileService answers it by signing with LibreSign's own engine.
 */
class SecurySignUnavailable extends LibresignException {
	public function __construct(\Throwable $previous) {
		parent::__construct('SecurySign is unavailable: ' . $previous->getMessage(), 503, $previous);
	}
}
