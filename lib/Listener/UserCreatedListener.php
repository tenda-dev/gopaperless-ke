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
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Puts every new account in the group allowed to request signing.
 *
 * Fast path at creation for provisioning routes that never load the web app
 * (bearer provisioning, occ, WhatsApp), where the `me` bootstrap catch-up never
 * runs. The grant lives in AccountService::ensureDefaultSignerGroup (shared,
 * idempotent, gated on a configured trusted provider), which `me` also calls as
 * the reliable catch-up for OIDC accounts whose UserCreatedEvent is unreliable.
 * Grant failures never interrupt account creation.
 *
 * @template-implements IEventListener<UserCreatedEvent>
 */
class UserCreatedListener implements IEventListener {
	public function __construct(
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserCreatedEvent)) {
			return;
		}
		try {
			$this->accountService->ensureDefaultSignerGroup($event->getUser());
		} catch (\Throwable $e) {
			// Defensive: ensureDefaultSignerGroup already swallows its own errors;
			// the listener must never let anything break account creation.
			$this->logger->error('[UserCreatedListener] default signer group grant failed', ['exception' => $e]);
		}
	}
}
