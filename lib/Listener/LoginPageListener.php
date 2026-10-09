<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\Util;

/**
 * Restyles Nextcloud's own login page as the GoPaperless card.
 *
 * Only a stylesheet and a small script are added. The form, its CSRF token,
 * brute-force throttling, two-factor and the alternative logins (SecurySign)
 * all stay Nextcloud's, so logging in works exactly as before.
 *
 * Turn it off without a deploy:
 * occ config:app:set libresign custom_login_page_enabled --value=false --type=boolean
 *
 * @template-implements IEventListener<BeforeLoginTemplateRenderedEvent>
 */
class LoginPageListener implements IEventListener {
	public function __construct(
		private IAppConfig $appConfig,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof BeforeLoginTemplateRenderedEvent)) {
			return;
		}

		if (!$this->appConfig->getValueBool(Application::APP_ID, 'custom_login_page_enabled', true)) {
			return;
		}

		// With a SecurySign provider set, its button is the only way in and reads
		// "Log In", with Register under it. login.ts keeps the form at
		// /login?direct=1 for a local admin account.
		$this->initialState->provideInitialState('login_provider_id',
			$this->appConfig->getValueInt(Application::APP_ID, 'securysign_provider_id', 0));
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
	}
}
