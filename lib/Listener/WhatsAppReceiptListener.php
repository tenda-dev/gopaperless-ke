<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Service\WhatsAppService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Sends "You signed …" to the chat a WhatsApp signing request came from.
 *
 * ponytail: runs inside the sign request, capped by the client's 3 s connect and
 * 10 s total timeouts. A background job if a slow payments service ever shows.
 *
 * @template-implements IEventListener<Event>
 */
class WhatsAppReceiptListener implements IEventListener {
	public function __construct(
		private WhatsAppService $whatsApp,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SignedEvent) {
			$this->whatsApp->receipt($event->getLibreSignFile(), $event->getSignRequest());
		}
	}
}
