<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Determines whether an account qualifies for trusted OIDC email verification.
 *
 * Trust is granted only to OIDC-backed accounts when every configured
 * user_oidc provider is explicitly trusted. Because the login event does not
 * identify the authenticating provider, any untrusted provider or evaluation
 * error causes trust to be withheld.
 */
class TrustedOidcProviderPolicy {
	private const USER_OIDC_APP_ID = 'user_oidc';

	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private UserOidcProviderService $userOidcProviderService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check whether the account can be trusted for OIDC-based email verification.
	 *
	 * Returns false when the account or provider configuration cannot be verified.
	 */
	public function isTrustedOidcAccount(?IUser $user): bool {
		if (!$user || $user->getUID() === '') {
			return false;
		}
		try {
			if (!$this->appManager->isEnabledForUser(self::USER_OIDC_APP_ID)) {
				return false;
			}
			$trustedIds = $this->trustedProviderIds();
			if ($trustedIds === []) {
				return false;
			}
			if (!$this->isOidcBackedUser($user)) {
				return false;
			}
			$configured = array_map(
				static fn (array $p): int => (int)$p['id'],
				$this->userOidcProviderService->getAvailableProviders(),
			);
			if ($configured === []) {
				return false;
			}
			// Without provider attribution, every configured provider must be trusted.
			foreach ($configured as $providerId) {
				if (!in_array($providerId, $trustedIds, true)) {
					return false;
				}
			}
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('[TrustedOidcProviderPolicy] trust evaluation failed; withholding trust', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Return the configured Mimi and SecurySign provider IDs.
	 *
	 * @return list<int>
	 */
	private function trustedProviderIds(): array {
		$ids = [];
		$mimi = $this->appConfig->getValueInt(Application::APP_ID, 'public_upload_login_provider_id', 0);
		$securysign = $this->appConfig->getValueInt(Application::APP_ID, 'securysign_provider_id', 0);
		if ($mimi > 0) {
			$ids[] = $mimi;
		}
		if ($securysign > 0 && $securysign !== $mimi) {
			$ids[] = $securysign;
		}
		return $ids;
	}

	/** True when the account is owned by the user_oidc backend, not the local one. */
	private function isOidcBackedUser(IUser $user): bool {
		return stripos($user->getBackendClassName(), 'oidc') !== false;
	}
}
