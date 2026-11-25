<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\OnboardingRecovery;

use OCA\Libresign\Helper\OnboardingRecoveryCookie;
use OCP\IRequest;
use OCP\IUser;

/**
 * Determines whether an authenticated user can resume a signing request from
 * their onboarding recovery cookie by matching the intended signer identity.
 *
 * This restores navigation only. Signing authorization remains enforced by
 * the signing workflow, so a failed identity match simply abandons recovery.
 */
class PendingOnboardingRecovery {
	public function __construct(
		private IRequest $request,
		private OnboardingRecoveryCookie $cookie,
	) {
	}

	/**
	 * @return array{pending: bool, uuid: ?string, clear: bool}
	 */
	public function evaluate(?IUser $current): array {
		$raw = $this->request->getCookie(OnboardingRecoveryCookie::NAME);
		if ($raw === null || $raw === '') {
			return ['pending' => false, 'uuid' => null, 'clear' => false];
		}

		$verified = $this->cookie->verify($raw);
		if ($verified === null || $current === null) {
			return ['pending' => false, 'uuid' => null, 'clear' => true];
		}

		// Normalize only for identity comparison. Plus-addressing and dot variations
		// remain distinct because they may represent separate accounts.
		$authed = strtolower(trim((string)$current->getEMailAddress()));
		$intended = strtolower(trim($verified['email']));
		if ($authed === '' || !hash_equals($intended, $authed)) {
			// Never resume a signing request under a different identity; discard the
			// recovery context instead.
			return ['pending' => false, 'uuid' => null, 'clear' => true];
		}

		return ['pending' => true, 'uuid' => $verified['uuid'], 'clear' => false];
	}
}
