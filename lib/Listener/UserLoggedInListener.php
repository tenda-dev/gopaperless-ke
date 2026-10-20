<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Recovers a missing account email from an email-shaped UID on login.
 *
 * Delegates to AccountService (populate-only; never sets verification state) and
 * swallows failures so nothing interrupts login. Trust is evaluated later, at
 * reconcile time, not here.
 *
 * @template-implements IEventListener<UserLoggedInEvent>
 */
class UserLoggedInListener implements IEventListener {
	public function __construct(
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent)) {
			return;
		}
		try {
			$this->accountService->recoverMissingEmailFromUid($event->getUser());
		} catch (\Throwable $e) {
			// Email recovery must never interrupt login.
			$this->logger->error('[UserLoggedInListener] account email recovery failed', ['exception' => $e]);
		}
	}
}
