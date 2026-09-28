<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * The document is prepared and waits for the user's passkey on SecurySign.
 * Not a failure: the browser opens SecurySign's signing frame with this and
 * sends the result back.
 */
class SecurySignApprovalRequired extends LibresignException {
	/**
	 * @param array{token: string, documentHash: string, documentName: string, origin: string} $approval
	 */
	public function __construct(
		public readonly array $approval,
	) {
		parent::__construct('Approve this signature with your SecurySign passkey.', 428);
	}
}
