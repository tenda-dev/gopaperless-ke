<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Events\SendSignNotificationEvent;
use OCA\Libresign\Service\WhatsAppService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Sends the signing request on WhatsApp alongside the email, for signers the
 * sender chose "Send sign request on Whatsapp" for.
 *
 * @template-implements IEventListener<Event>
 */
class WhatsAppRequestListener implements IEventListener {
	public function __construct(
		private WhatsAppService $whatsApp,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SendSignNotificationEvent) {
			$this->whatsApp->requested($event->getLibreSignFile(), $event->getSignRequest());
		}
	}
}
