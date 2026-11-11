<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\AccountRecovery;

use OCA\Libresign\Helper\RecoveryCookie;
use OCP\IRequest;
use OCP\IUser;

/**
 * Determines whether the authenticated user has a pending account recovery.
 *
 * A recovery is pending only when the cookie is valid and bound to the
 * current user's UID. Invalid, expired or mismatched cookies are marked
 * for clearing.
 *
 * This service only controls whether the recovery prompt is offered.
 * The signing page's existing authorization gate remains authoritative.
 */
class PendingRecovery {
	public function __construct(
		private IRequest $request,
		private RecoveryCookie $recoveryCookie,
	) {
	}

	/**
	 * @return array{pending: bool, clear: bool}
	 */
	public function evaluate(?IUser $current): array {
		$raw = $this->request->getCookie(RecoveryCookie::NAME);
		if ($raw === null || $raw === '') {
			return ['pending' => false, 'clear' => false];
		}

		$verified = $this->recoveryCookie->verify($raw);
		if ($verified === null || $current === null) {
			return ['pending' => false, 'clear' => true];
		}

		if (!$this->recoveryCookie->isBoundTo($verified, $current->getUID())) {
			return ['pending' => false, 'clear' => true];
		}

		return ['pending' => true, 'clear' => false];
	}
}
