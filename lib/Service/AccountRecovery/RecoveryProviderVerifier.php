<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\AccountRecovery;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\UserOidcProviderService;
use OCP\IAppConfig;

/**
 * Provides the verified provider ID used by both account-recovery entry
 * points. A configured ID is usable only while the provider is offered
 * by user_oidc.
 */
class RecoveryProviderVerifier {
	public function __construct(
		private IAppConfig $appConfig,
		private UserOidcProviderService $providers,
	) {
	}

	public function verifiedId(): int {
		$id = $this->appConfig->getValueInt(Application::APP_ID, 'securysign_provider_id', 0);
		if ($id <= 0) {
			return 0;
		}

		try {
			foreach ($this->providers->getAvailableProviders() as $provider) {
				if ((int)($provider['id'] ?? 0) === $id) {
					return $id;
				}
			}
		} catch (\Throwable) {
			// Unverifiable is the same as unavailable.
		}

		return 0;
	}
}
