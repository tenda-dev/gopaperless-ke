<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\WhatsAppSignController;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
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
		private IRequest $request,
		private IURLGenerator $urls,
	) {
	}

	/**
	 * Nobody can sign in inside WhatsApp's browser, so on its way to one of our
	 * pages (the receipt's "View Document", "Sign another document") it goes to the
	 * same hand-off as signing. Limited to our pages so that an app's own web view
	 * logging in to Nextcloud is left alone.
	 */
	private function leaveWhatsAppBrowser(): void {
		$to = (string)$this->request->getParam('redirect_url', '');
		if (!WhatsAppSignController::isHandOffPath($to)
			|| WhatsAppSignController::browserLinks($this->request->getHeader('User-Agent'), $to) === null) {
			return;
		}
		// ponytail: a meta refresh, because this event can't answer with a redirect.
		Util::addHeader('meta', [
			'http-equiv' => 'refresh',
			'content' => '0;url=' . $this->urls->linkToRoute('libresign.whatsAppSign.openInBrowser', ['to' => $to]),
		]);
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof BeforeLoginTemplateRenderedEvent) {
			$this->leaveWhatsAppBrowser();
		}
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'custom_login_page_enabled', true)) {
			return;
		}

		// Nextcloud's footer reads "Terms & Conditions", not "Legal notice"
		// (src/legal-links.ts). The login page has its own event, not this one.
		Util::addScript(Application::APP_ID, 'libresign-legal-links');
		if (!($event instanceof BeforeLoginTemplateRenderedEvent)) {
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
