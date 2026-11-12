<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Enum;

/**
 * Defines the account-recovery flow for a signer authenticated as another account.
 */
enum RecoveryPlan: string {
	/** Preserve the existing client-side single-logout flow for OIDC sessions. */
	case CLIENT_SLS = 'client_sls';
	/** Native session to an OIDC signer: local logout, then the OIDC provider. */
	case SERVER_OIDC = 'server_oidc';
	/** The signer has a native account: local logout, then the password form. */
	case SERVER_NATIVE = 'server_native';
	/**
	 * Generic login when no usable Securysign provider is available or the
	 * signer uses a backend that cannot use the provider-specific flow.
	 */
	case SERVER_LOGIN = 'server_login';

	/** True when the recovery endpoint, not the page's own script, performs the switch. */
	public function isServerHandled(): bool {
		return $this !== self::CLIENT_SLS;
	}
}
