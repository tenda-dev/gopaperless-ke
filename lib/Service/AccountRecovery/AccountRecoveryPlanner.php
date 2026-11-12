<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\AccountRecovery;

use OCA\Libresign\Enum\RecoveryPlan;
use OCP\IUser;

/**
 * Selects the account-recovery flow based on the intended signer's
 * authentication backend and the availability of a verified OIDC provider.
 *
 * The planner is deterministic and performs no I/O. The caller is
 * responsible for establishing that the current account is a mismatch
 * and supplying a verified provider ID.
 *
 * Native accounts use the password login, OIDC accounts use the verified
 * provider when available, and other backends use the generic login flow.
 */
final class AccountRecoveryPlanner {
	public const OIDC_BACKEND = 'user_oidc';
	public const NATIVE_BACKEND = 'Database';

	public static function plan(?IUser $current, IUser $signer, int $verifiedProviderId): RecoveryPlan {
		$signerBackend = $signer->getBackendClassName();
		if ($signerBackend === self::NATIVE_BACKEND) {
			return RecoveryPlan::SERVER_NATIVE;
		}
		if ($signerBackend !== self::OIDC_BACKEND || $verifiedProviderId <= 0) {
			return RecoveryPlan::SERVER_LOGIN;
		}
		if ($current !== null && $current->getBackendClassName() === self::OIDC_BACKEND) {
			return RecoveryPlan::CLIENT_SLS;
		}

		return RecoveryPlan::SERVER_OIDC;
	}
}
