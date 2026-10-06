<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * The local engine is about to sign for someone who has not confirmed their
 * MIMI passkey. SecurySign's frame would have asked; the local engine cannot.
 */
class MimiPasskeyRequired extends LibresignException {
	public function __construct(
		public readonly string $redirect,
	) {
		parent::__construct('Confirm it is you with your MIMI passkey, then sign.', 403);
	}
}
