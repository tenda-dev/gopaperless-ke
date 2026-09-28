<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Login;

use OCA\Libresign\AppInfo\Application;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Adds "Register" to Nextcloud's login page.
 *
 * Accounts are not created in Nextcloud. The link starts the tendaworld.com
 * onboarding, which verifies the visitor's Google email through SecurySign and
 * then shows the paywall, and entitled accounts reach GoPaperless by SSO.
 *
 * Point it elsewhere, or remove it with an empty value, without a deploy:
 * occ config:app:set libresign register_url --value=https://tendaworld.com/get-started
 */
class RegisterLoginProvider implements IAlternativeLoginProvider {
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getAlternativeLogins(): array {
		$url = $this->appConfig->getValueString(Application::APP_ID, 'register_url', 'https://tendaworld.com/get-started');
		if ($url === '') {
			return [];
		}

		return [new class($this->l10n->t('Register'), $url) implements IAlternativeLogin {
			public function __construct(
				private string $label,
				private string $link,
			) {
			}

			public function getLabel(): string {
				return $this->label;
			}

			public function getLink(): string {
				return $this->link;
			}

			// Hook for login.scss, which orders and styles the button.
			public function getClass(): string {
				return 'gp-register';
			}

			public function load(): void {
			}
		}];
	}
}
