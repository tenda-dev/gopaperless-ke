<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Lets pages frame SecurySign's signing window. LibreSign's pages send
 * frame-src 'self', which the browser answers with a broken frame. Only the
 * configured SecurySign origin is added, and only while the integration is on.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class SecurySignCspListener implements IEventListener {
	public function __construct(
		private IAppConfig $config,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent
			|| $this->config->getValueInt(Application::APP_ID, 'securysign_provider_id', 0) <= 0) {
			return;
		}
		try {
			$origin = SecurySignService::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		} catch (\RuntimeException) {
			return;
		}
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedFrameDomain($origin);
		$event->addPolicy($policy);
	}
}
