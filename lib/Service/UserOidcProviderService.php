<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCP\App\IAppManager;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovers configured User OIDC providers for the Public Upload login
 * provider setting.
 *
 * User OIDC exposes its providers through Nextcloud's alternative login
 * provider API. The provider id is extracted from the login link because
 * IAlternativeLogin does not expose it directly.
 *
 * @psalm-type UserOidcProviderOption = array{id: int, label: string}
 */
class UserOidcProviderService {
	private const USER_OIDC_ALTERNATIVE_LOGIN_PROVIDER_CLASS = '\OCA\UserOIDC\AlternativeLogin\AlternativeLoginProvider';
	private const USER_OIDC_APP_ID = 'user_oidc';
	// User OIDC's alternative login links use /login/{providerId}.
	private const PROVIDER_ID_PATTERN = '#/login/(\d+)#';

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<UserOidcProviderOption>
	 */
	public function getAvailableProviders(): array {
		if (!class_exists(self::USER_OIDC_ALTERNATIVE_LOGIN_PROVIDER_CLASS)) {
			return [];
		}
		if (!$this->appManager->isEnabledForUser(self::USER_OIDC_APP_ID)) {
			return [];
		}

		try {
			$provider = \OCP\Server::get(self::USER_OIDC_ALTERNATIVE_LOGIN_PROVIDER_CLASS);
		} catch (Throwable $e) {
			// Best-effort discovery for an admin-settings convenience dropdown:
			// this renders unconditionally on every admin settings page load
			// (not behind a feature gate), so a resolution failure must degrade
			// to "no providers listed" rather than break the page.
			$this->logger->debug('Could not resolve the User OIDC alternative login provider', ['exception' => $e]);
			return [];
		}

		if (!$provider instanceof IAlternativeLoginProvider) {
			return [];
		}

		$options = [];
		foreach ($provider->getAlternativeLogins() as $alternativeLogin) {
			$option = $this->toOption($alternativeLogin);
			if ($option !== null) {
				$options[] = $option;
			}
		}
		return $options;
	}

	/**
	 * Convert a User OIDC login option into the value used by the admin selector.
	 *
	 * Non-provider login options are ignored.
	 *
	 * @return UserOidcProviderOption|null
	 */
	private function toOption(IAlternativeLogin $alternativeLogin): ?array {
		if (preg_match(self::PROVIDER_ID_PATTERN, $alternativeLogin->getLink(), $matches) !== 1) {
			// Not a User OIDC provider link in the expected shape
			return null;
		}
		return [
			'id' => (int)$matches[1],
			'label' => $alternativeLogin->getLabel(),
		];
	}
}
