<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Service\TuandikianeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Tells Tuandikiane about each signature on one of its drafts, and that the
 * draft is complete after the last. Runs on the event itself: the file's
 * callback URL never fires (SignedCallbackListener).
 *
 * ponytail: inside the signing request, like the WhatsApp receipt, capped by the
 * client's 3 s connect and 10 s timeouts. A background job if that ever shows.
 *
 * @template-implements IEventListener<Event>
 */
class TuandikianeSignedListener implements IEventListener {
	public function __construct(
		private TuandikianeService $tuandikiane,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SignedEvent) {
			$this->tuandikiane->signed($event->getLibreSignFile(), $event->getSignRequest());
		}
	}
}
